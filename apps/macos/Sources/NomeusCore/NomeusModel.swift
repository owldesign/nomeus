import Foundation
import Observation

/// Something the model can tell the user outside the menu. The app wires UNUserNotificationCenter;
/// tests wire a recorder. Only fires on transitions, never on a steady state.
public protocol Notifying: AnyObject {
    func notify(title: String, body: String)
}

/// The one observable store behind the menu and the dashboard window.
/// Everything here is main-actor; network calls hop off via the client and land back here.
@MainActor
@Observable
public final class NomeusModel {
    public private(set) var status: StatusSnapshot?
    public private(set) var sites: [Site] = []
    public private(set) var services: [Service] = []
    public private(set) var health: Health = .unreachable
    /// Shown in the panel. A refresh error clears itself on the next good refresh; an action error
    /// (a failed task, a 4xx on a button) stays until the next action or `dismissError()` — otherwise the
    /// poll that follows every action would wipe it before anyone read it.
    public private(set) var lastError: String?
    @ObservationIgnored private var errorIsFromRefresh = false
    public private(set) var lastRefresh: Date?
    /// Instance names with a lifecycle task in flight — the row shows a spinner and disables its buttons.
    public private(set) var busy: Set<String> = []

    @ObservationIgnored public var client: APIClient
    @ObservationIgnored public weak var notifier: Notifying?
    /// Called when a redirect moved the base (http → https, another tld). The app persists it.
    @ObservationIgnored public var onBaseChange: ((URL) -> Void)?

    public init(client: APIClient, notifier: Notifying? = nil) {
        self.client = client
        self.notifier = notifier
    }

    /// The URL the dashboard window loads: what the API says it's linked as, else the client base.
    public var dashboardURL: URL {
        if let url = status.flatMap({ URL(string: $0.dashboard.url) }) { return url }
        return client.baseURL
    }

    public var version: String? { status?.nomeus.version }

    // MARK: refresh

    /// Cheap poll: /api/status only (port probes). Safe every few seconds.
    public func refreshStatus() async {
        do {
            let next = try await call { try await $0.status() }
            apply(next)
        } catch {
            fail(error)
        }
    }

    /// Full refresh for when the menu is open: status + sites + services (services hits launchctl per instance).
    public func refreshAll() async {
        await refreshStatus()
        guard status != nil, health != .unreachable else { return }
        do {
            self.sites = try await call { try await $0.sites() }
            self.services = try await call { try await $0.services() }
            clearRefreshError()
        } catch {
            fail(error)
        }
    }

    /// Exposed for tests and for the app's notifier wiring; the app normally goes through refresh*.
    public func apply(_ next: StatusSnapshot) {
        if let previous = status {
            for name in stoppedInstances(previous: previous.instances, current: next.instances) {
                let type = next.instances.first { $0.name == name }?.type ?? "service"
                let port = next.instances.first { $0.name == name }?.port ?? 0
                notifier?.notify(title: "\(name) stopped", body: "The \(type) instance on port \(port) is no longer answering.")
            }
        }
        status = next
        health = Health(instances: next.instances)
        clearRefreshError()
        lastRefresh = Date()
    }

    // MARK: lifecycle

    public func start(_ name: String) async { await run(name) { try await $0.start(service: name) } }
    public func stop(_ name: String) async { await run(name) { try await $0.stop(service: name) } }
    public func restart(_ name: String) async { await run(name) { try await $0.restart(service: name) } }

    private func run(_ name: String, _ action: @escaping (APIClient) async throws -> TaskRecord) async {
        guard !busy.contains(name) else { return }
        busy.insert(name)
        defer { busy.remove(name) }
        dismissError()
        do {
            let task = try await call(action)
            _ = try await call { try await $0.wait(for: task) }
        } catch {
            lastError = (error as? APIError)?.errorDescription ?? error.localizedDescription
            errorIsFromRefresh = false
        }
        await refreshAll()
    }

    public func dismissError() {
        lastError = nil
        errorIsFromRefresh = false
    }

    // MARK: helpers

    private func clearRefreshError() {
        if errorIsFromRefresh { dismissError() }
    }

    /// One retry on a redirect: adopt the origin nginx pointed at (scheme + host + port), keep the path,
    /// re-issue with the same method. Anything else propagates.
    private func call<T>(_ op: (APIClient) async throws -> T) async throws -> T {
        do {
            return try await op(client)
        } catch APIError.redirected(let target) {
            guard var origin = URLComponents(url: target, resolvingAgainstBaseURL: false) else { throw APIError.redirected(target) }
            origin.path = ""; origin.query = nil; origin.fragment = nil
            guard let base = origin.url, base != client.baseURL else { throw APIError.redirected(target) }
            client = client.rebased(to: base)
            onBaseChange?(base)
            return try await op(client)
        }
    }

    private func fail(_ error: Error) {
        // don't paper over an action error with the refresh that followed it
        if lastError == nil || errorIsFromRefresh {
            lastError = (error as? APIError)?.errorDescription ?? error.localizedDescription
            errorIsFromRefresh = true
        }
        if case .unreachable = error as? APIError {
            health = .unreachable
        }
    }
}
