import XCTest
@testable import NomeusCore

final class HealthTests: XCTestCase {
    private func inst(_ name: String, _ running: Bool) -> StatusSnapshot.Instance {
        .init(name: name, type: "x", port: 1, running: running)
    }

    func testNoInstancesIsOk() {
        XCTAssertEqual(Health(instances: []), .ok)
    }

    func testAllRunningIsOk() {
        XCTAssertEqual(Health(instances: [inst("a", true), inst("b", true)]), .ok)
    }

    func testSomeDownIsDegradedWithCount() {
        XCTAssertEqual(Health(instances: [inst("a", true), inst("b", false), inst("c", false)]), .degraded(down: 2))
        XCTAssertEqual(Health(instances: [inst("a", true), inst("b", false)]).label, "1 service down")
    }

    func testNoneRunningIsDown() {
        XCTAssertEqual(Health(instances: [inst("a", false)]), .down)
    }

    func testStoppedInstancesOnlyReportsTransitions() {
        let before = [inst("pg", true), inst("redis", true), inst("meili", false)]
        let after = [inst("pg", false), inst("redis", true), inst("meili", false)]
        XCTAssertEqual(stoppedInstances(previous: before, current: after), ["pg"])
        XCTAssertEqual(stoppedInstances(previous: after, current: after), [])
        // an instance that was already down stays silent; a new one that appears down is not a transition
        XCTAssertEqual(stoppedInstances(previous: before, current: after + [inst("new", false)]), ["pg"])
    }
}

@MainActor
final class NomeusModelTests: XCTestCase {
    func testRefreshStatusSetsHealthAndVersion() async {
        let t = FakeTransport()
        t.on("GET /api/status", body: Fixture.status())
        let m = NomeusModel(client: Fixture.client(t))

        await m.refreshStatus()

        XCTAssertEqual(m.health, .ok)
        XCTAssertEqual(m.version, "2.1.0")
        XCTAssertEqual(m.dashboardURL.absoluteString, "http://nomeus.test")
        XCTAssertNil(m.lastError)
        XCTAssertNotNil(m.lastRefresh)
    }

    func testUnreachableApiFlipsHealthAndKeepsTheLastSnapshot() async {
        let t = FakeTransport()
        t.on("GET /api/status", body: Fixture.status())
        let m = NomeusModel(client: Fixture.client(t))
        await m.refreshStatus()

        t.failWith = URLError(.cannotConnectToHost)
        await m.refreshStatus()

        XCTAssertEqual(m.health, .unreachable)
        XCTAssertEqual(m.version, "2.1.0", "the last good snapshot is kept for the header")
        XCTAssertNotNil(m.lastError)
    }

    func testRefreshAllLoadsSitesAndServicesOnlyWhenReachable() async {
        let t = FakeTransport()
        t.on("GET /api/status", body: Fixture.status())
        t.on("GET /api/sites", body: Fixture.sites)
        t.on("GET /api/services", body: Fixture.services())
        let m = NomeusModel(client: Fixture.client(t))

        await m.refreshAll()
        XCTAssertEqual(m.sites.map(\.name), ["nomeus", "shop", "vite"])
        XCTAssertEqual(m.services.map(\.name), ["pg", "redis"])

        t.failWith = URLError(.timedOut)
        let before = t.requests.count
        await m.refreshAll()
        XCTAssertEqual(t.requests.count, before + 1, "only /api/status is tried when the API is down")
    }

    func testStopRunsTheTaskThenRefreshes() async {
        let t = FakeTransport()
        var statusCalls = 0
        t.on("GET /api/status") { _ in
            statusCalls += 1
            // after the stop, pg reports not running
            return (200, Fixture.status(instances: [("pg", "postgresql", 5432, statusCalls == 1), ("redis", "redis", 6379, true)]))
        }
        t.on("GET /api/sites", body: Fixture.sites)
        t.on("GET /api/services", body: Fixture.services(pgRunning: false))
        t.on("POST /api/services/pg/stop", status: 202, body: #"{"task":\#(Fixture.task(status: "queued"))}"#)
        var polls = 0
        t.on("GET /api/tasks/t1") { _ in
            polls += 1
            return (200, #"{"data":\#(Fixture.task(status: polls == 1 ? "running" : "done", exit: polls == 1 ? nil : 0))}"#)
        }
        let notifier = RecordingNotifier()
        let m = NomeusModel(client: Fixture.client(t), notifier: notifier)
        await m.refreshStatus()

        await m.stop("pg")

        XCTAssertEqual(polls, 2)
        XCTAssertNil(m.lastError)
        XCTAssertTrue(m.busy.isEmpty)
        XCTAssertEqual(m.health, .degraded(down: 1))
        XCTAssertFalse(m.services[0].status.running)
        XCTAssertTrue(t.requests.contains("POST /api/services/pg/stop"))
        // a user-initiated stop is still a running → stopped transition; the notification is the truthful signal
        XCTAssertEqual(notifier.sent.map(\.title), ["pg stopped"])
    }

    func testFailedTaskSurfacesAsLastError() async {
        let t = FakeTransport()
        t.on("GET /api/status", body: Fixture.status())
        t.on("GET /api/sites", body: Fixture.sites)
        t.on("GET /api/services", body: Fixture.services())
        t.on("POST /api/services/pg/start", status: 202, body: #"{"task":\#(Fixture.task(status: "queued", label: "services:start pg"))}"#)
        t.on("GET /api/tasks/t1", body: #"{"data":\#(Fixture.task(status: "failed", exit: 1, label: "services:start pg"))}"#)
        let m = NomeusModel(client: Fixture.client(t))

        await m.start("pg")

        XCTAssertEqual(m.lastError, "services:start pg failed — see the Tasks page")
        XCTAssertTrue(m.busy.isEmpty)
    }

    func testValidationErrorFromLaravelIsShownNotSwallowed() async {
        let t = FakeTransport()
        t.on("GET /api/status", body: Fixture.status())
        t.on("GET /api/sites", body: Fixture.sites)
        t.on("GET /api/services", body: Fixture.services())
        t.on("POST /api/services/pg/restart", status: 422, body: #"{"message":"pg is not installed."}"#)
        let m = NomeusModel(client: Fixture.client(t))

        await m.restart("pg")

        XCTAssertEqual(m.lastError, "HTTP 422: pg is not installed.")

        // the polls that follow must not wipe it — that was the "405 that quickly goes away" in the runbook
        await m.refreshStatus()
        await m.refreshAll()
        XCTAssertEqual(m.lastError, "HTTP 422: pg is not installed.")

        m.dismissError()
        XCTAssertNil(m.lastError)
    }

    func testARefreshErrorClearsItselfButAnActionErrorDoesNot() async {
        let t = FakeTransport()
        t.on("GET /api/status", body: Fixture.status())
        t.on("GET /api/sites", body: Fixture.sites)
        t.on("GET /api/services", body: Fixture.services())
        t.on("POST /api/services/pg/start", status: 500, body: #"{"message":"boom"}"#)
        let m = NomeusModel(client: Fixture.client(t))

        t.failWith = URLError(.timedOut)
        await m.refreshStatus()
        XCTAssertNotNil(m.lastError)
        t.failWith = nil
        await m.refreshStatus()
        XCTAssertNil(m.lastError, "a refresh error goes away on the next good refresh")

        await m.start("pg")
        XCTAssertEqual(m.lastError, "HTTP 500: boom")
        t.failWith = URLError(.timedOut)
        await m.refreshStatus()
        XCTAssertEqual(m.lastError, "HTTP 500: boom", "a later refresh failure doesn't replace the action error")
        XCTAssertEqual(m.health, .unreachable, "but health still tracks the refresh")

        await m.stop("pg")   // next action clears the previous one before it runs
        XCTAssertNotEqual(m.lastError, "HTTP 500: boom")
    }

    func testNotifiesOnlyOnRunningToStoppedTransitions() {
        let notifier = RecordingNotifier()
        let m = NomeusModel(client: Fixture.client(FakeTransport()), notifier: notifier)
        let up = snapshot([("pg", true), ("redis", true)])
        let pgDown = snapshot([("pg", false), ("redis", true)])

        m.apply(up)
        XCTAssertTrue(notifier.sent.isEmpty, "first snapshot has nothing to compare against")
        m.apply(pgDown)
        XCTAssertEqual(notifier.sent.count, 1)
        XCTAssertEqual(notifier.sent[0].title, "pg stopped")
        XCTAssertTrue(notifier.sent[0].body.contains("5432"))
        m.apply(pgDown)
        XCTAssertEqual(notifier.sent.count, 1, "steady state is silent")
        m.apply(up)
        XCTAssertEqual(notifier.sent.count, 1, "coming back up is not a notification")
    }

    /// The 1.3 finding: `valet secure nomeus` makes nginx 301 http → https; URLSession re-issued the POST as a
    /// GET and every stop/start was a 405. Now: adopt the https origin, retry once with POST, remember it.
    func testStopOnAnHttpBaseThatRedirectsToHttpsRetriesWithPostOnHttps() async {
        let t = FakeTransport()
        // http origin: everything 301s to https, like nginx's secured server block
        for (method, path) in [("GET", "/api/status"), ("GET", "/api/sites"), ("GET", "/api/services"), ("POST", "/api/services/pg/stop"), ("GET", "/api/tasks/t1")] {
            t.redirect("\(method) http://nomeus.test\(path)", to: "https://nomeus.test\(path)")
        }
        // https origin answers
        t.on("GET https://nomeus.test/api/status", body: Fixture.status(instances: [("pg", "postgresql", 5432, false), ("redis", "redis", 6379, true)]))
        t.on("GET https://nomeus.test/api/sites", body: Fixture.sites)
        t.on("GET https://nomeus.test/api/services", body: Fixture.services(pgRunning: false))
        t.on("POST https://nomeus.test/api/services/pg/stop", status: 202, body: #"{"task":\#(Fixture.task(status: "queued"))}"#)
        t.on("GET https://nomeus.test/api/tasks/t1", body: #"{"data":\#(Fixture.task(status: "done", exit: 0))}"#)

        var learned: [URL] = []
        let m = NomeusModel(client: Fixture.client(t))   // base is http://nomeus.test
        m.onBaseChange = { learned.append($0) }

        await m.stop("pg")

        XCTAssertNil(m.lastError)
        XCTAssertEqual(learned, [URL(string: "https://nomeus.test")!], "rebased exactly once")
        XCTAssertEqual(m.client.baseURL.absoluteString, "https://nomeus.test")
        // the POST was re-issued as a POST, to https, then everything after went straight to https
        XCTAssertEqual(t.urls.prefix(3).map { $0 }, [
            "http://nomeus.test/api/services/pg/stop",
            "https://nomeus.test/api/services/pg/stop",
            "https://nomeus.test/api/tasks/t1",
        ])
        XCTAssertFalse(t.requests.contains("GET /api/services/pg/stop"))
        XCTAssertEqual(m.health, .degraded(down: 1))
    }

    func testARedirectLoopIsAnErrorNotARetryStorm() async {
        let t = FakeTransport()
        t.redirect("GET /api/status", to: "http://nomeus.test/api/status")   // same origin → nothing to learn
        let m = NomeusModel(client: Fixture.client(t))

        await m.refreshStatus()

        XCTAssertEqual(t.requests.count, 1)
        XCTAssertEqual(m.lastError, "Redirected to http://nomeus.test/api/status")
    }

    func testDashboardUrlFallsBackToTheClientBase() {
        let m = NomeusModel(client: Fixture.client(FakeTransport()))
        XCTAssertEqual(m.dashboardURL, Fixture.base)
    }

    private func snapshot(_ list: [(String, Bool)]) -> StatusSnapshot {
        StatusSnapshot(
            nomeus: .init(version: "2.1.0"),
            valet: .init(installed: true, tld: "test"),
            dashboard: .init(url: "http://nomeus.test", linked: true),
            instances: list.map { .init(name: $0.0, type: $0.0 == "pg" ? "postgresql" : "redis", port: $0.0 == "pg" ? 5432 : 6379, running: $0.1) }
        )
    }
}
