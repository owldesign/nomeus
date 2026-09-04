import XCTest
@testable import NomeusCore

final class APIClientTests: XCTestCase {
    func testStatusDecodesTheSnapshotShape() async throws {
        let t = FakeTransport()
        t.on("GET /api/status", body: Fixture.status())
        let s = try await Fixture.client(t).status()

        XCTAssertEqual(s.nomeus.version, "2.1.0")
        XCTAssertEqual(s.valet.tld, "test")
        XCTAssertEqual(s.dashboard.url, "http://nomeus.test")
        XCTAssertEqual(s.instances.map(\.name), ["pg", "redis"])
        XCTAssertTrue(s.instances[0].running)
    }

    func testSitesUnwrapTheDataEnvelope() async throws {
        let t = FakeTransport()
        t.on("GET /api/sites", body: Fixture.sites)
        let sites = try await Fixture.client(t).sites()

        XCTAssertEqual(sites.count, 3)
        XCTAssertEqual(sites[1].php, "8.3")
        XCTAssertTrue(sites[1].secured)
        XCTAssertEqual(sites[2].type, "proxy")
        XCTAssertNil(sites[0].php)
    }

    func testServicesDecodeNestedStatusAndNullPid() async throws {
        let t = FakeTransport()
        t.on("GET /api/services", body: Fixture.services(pgRunning: false))
        let services = try await Fixture.client(t).services()

        XCTAssertFalse(services[0].status.running)
        XCTAssertNil(services[0].status.pid)
        XCTAssertEqual(services[1].status.pid, 4243)
        XCTAssertEqual(services[0].formula, "postgresql@17")
    }

    func testStopPostsJsonWithTheNomeusHeaderAndReturnsThe202Task() async throws {
        let t = FakeTransport()
        t.on("POST /api/services/pg/stop") { req in
            XCTAssertEqual(req.value(forHTTPHeaderField: "Accept"), "application/json")
            XCTAssertEqual(req.value(forHTTPHeaderField: "Content-Type"), "application/json")
            XCTAssertEqual(req.value(forHTTPHeaderField: "X-Nomeus"), "1", "RequireNomeusHeader guards every unsafe method")
            return (202, #"{"task":\#(Fixture.task(status: "queued"))}"#)
        }
        let task = try await Fixture.client(t).stop(service: "pg")

        XCTAssertEqual(task.id, "t1")
        XCTAssertEqual(task.status, "queued")
        XCTAssertFalse(task.isFinished)
    }

    func testWaitPollsUntilDone() async throws {
        let t = FakeTransport()
        var polls = 0
        t.on("GET /api/tasks/t1") { _ in
            polls += 1
            let status = polls < 3 ? "running" : "done"
            return (200, #"{"data":\#(Fixture.task(status: status, exit: polls < 3 ? nil : 0))}"#)
        }
        let done = try await Fixture.client(t).wait(for: TaskRecord(id: "t1", label: "services:stop pg", status: "queued"))

        XCTAssertEqual(done.status, "done")
        XCTAssertEqual(polls, 3)
    }

    func testWaitThrowsWhenTheTaskFails() async {
        let t = FakeTransport()
        t.on("GET /api/tasks/t1", body: #"{"data":\#(Fixture.task(status: "failed", exit: 1))}"#)
        do {
            _ = try await Fixture.client(t).wait(for: TaskRecord(id: "t1", label: "services:stop pg", status: "running"))
            XCTFail("expected taskFailed")
        } catch let error as APIError {
            XCTAssertEqual(error, .taskFailed("services:stop pg"))
        } catch {
            XCTFail("wrong error \(error)")
        }
    }

    func testWaitReturnsImmediatelyForAFinishedTask() async throws {
        let t = FakeTransport()
        let done = try await Fixture.client(t).wait(for: TaskRecord(id: "t9", label: "x", status: "done", exitCode: 0))
        XCTAssertEqual(done.id, "t9")
        XCTAssertTrue(t.requests.isEmpty)
    }

    func testNon2xxBecomesHttpErrorWithLaravelMessage() async {
        let t = FakeTransport()
        t.on("POST /api/services/nope/start", status: 404, body: #"{"message":"No service [nope]."}"#)
        do {
            _ = try await Fixture.client(t).start(service: "nope")
            XCTFail("expected http error")
        } catch let error as APIError {
            XCTAssertEqual(error, .http(404, "No service [nope]."))
        } catch {
            XCTFail("wrong error \(error)")
        }
    }

    func testTransportFailureBecomesUnreachable() async {
        let t = FakeTransport()
        t.failWith = URLError(.cannotConnectToHost)
        do {
            _ = try await Fixture.client(t).status()
            XCTFail("expected unreachable")
        } catch let error as APIError {
            if case .unreachable = error {} else { XCTFail("wrong case \(error)") }
        } catch {
            XCTFail("wrong error \(error)")
        }
    }

    func testPathsAreJoinedAgainstAHostOnlyBase() async throws {
        let t = FakeTransport()
        t.on("GET /api/status", body: Fixture.status())
        _ = try await Fixture.client(t).status()
        XCTAssertEqual(t.requests, ["GET /api/status"])
    }

    func testMalformedJsonIsADecodingError() async {
        let t = FakeTransport()
        t.on("GET /api/status", body: "<html>nginx 502</html>")
        do {
            _ = try await Fixture.client(t).status()
            XCTFail("expected decoding error")
        } catch let error as APIError {
            if case .decoding = error {} else { XCTFail("wrong case \(error)") }
        } catch {
            XCTFail("wrong error \(error)")
        }
    }

    func testARedirectIsReportedNotFollowed() async {
        let t = FakeTransport()
        t.redirect("POST /api/services/pg/stop", to: "https://nomeus.test/api/services/pg/stop")
        do {
            _ = try await Fixture.client(t).stop(service: "pg")
            XCTFail("expected redirected")
        } catch let error as APIError {
            XCTAssertEqual(error, .redirected(URL(string: "https://nomeus.test/api/services/pg/stop")!))
        } catch {
            XCTFail("wrong error \(error)")
        }
        XCTAssertEqual(t.requests, ["POST /api/services/pg/stop"], "one request; nothing was re-issued as GET")
    }

    func testRelativeLocationResolvesAgainstTheRequest() async {
        let t = FakeTransport()
        t.redirect("GET /api/status", to: "/api/status/")
        do {
            _ = try await Fixture.client(t).status()
            XCTFail("expected redirected")
        } catch APIError.redirected(let url) {
            XCTAssertEqual(url.absoluteString, "http://nomeus.test/api/status/")
        } catch {
            XCTFail("wrong error \(error)")
        }
    }
}
