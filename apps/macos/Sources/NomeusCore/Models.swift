import Foundation

// Mirrors of the JSON the Laravel side already emits (app/Support/*::toArray, StatusService::snapshot).
// Decoded with .convertFromSnakeCase; only the fields the shell needs are declared.

public struct StatusSnapshot: Decodable, Equatable, Sendable {
    public struct Nomeus: Decodable, Equatable, Sendable {
        public let version: String
        public init(version: String) { self.version = version }
    }
    public struct Valet: Decodable, Equatable, Sendable {
        public let installed: Bool
        public let tld: String
        public init(installed: Bool, tld: String) { self.installed = installed; self.tld = tld }
    }
    public struct Dashboard: Decodable, Equatable, Sendable {
        public let url: String
        public let linked: Bool
        public init(url: String, linked: Bool) { self.url = url; self.linked = linked }
    }
    public struct Instance: Decodable, Equatable, Identifiable, Sendable {
        public var id: String { name }
        public let name: String
        public let type: String
        public let port: Int
        public let running: Bool

        public init(name: String, type: String, port: Int, running: Bool) {
            self.name = name; self.type = type; self.port = port; self.running = running
        }
    }

    public let nomeus: Nomeus
    public let valet: Valet
    public let dashboard: Dashboard
    public let instances: [Instance]

    public init(nomeus: Nomeus, valet: Valet, dashboard: Dashboard, instances: [Instance]) {
        self.nomeus = nomeus; self.valet = valet; self.dashboard = dashboard; self.instances = instances
    }
}

public struct Site: Decodable, Equatable, Identifiable, Sendable {
    public var id: String { name }
    public let name: String
    public let host: String
    public let url: String
    public let type: String       // parked | linked | proxy
    public let path: String
    public let secured: Bool
    public let php: String?
    public let laravel: Bool
}

public struct Service: Decodable, Equatable, Identifiable, Sendable {
    public struct Status: Decodable, Equatable, Sendable {
        public let running: Bool
        public let loaded: Bool
        public let pid: Int?
        public let crashing: Bool
        public let disabled: Bool
        public let installed: Bool
    }
    public var id: String { name }
    public let name: String
    public let type: String
    public let formula: String
    public let version: String
    public let port: Int
    public let status: Status
}

public struct TaskRecord: Decodable, Equatable, Sendable {
    public let id: String
    public let label: String
    public let status: String     // queued | running | done | failed
    public let exitCode: Int?

    public init(id: String, label: String, status: String, exitCode: Int? = nil) {
        self.id = id; self.label = label; self.status = status; self.exitCode = exitCode
    }

    public var isFinished: Bool { status == "done" || status == "failed" }
    public var failed: Bool { status == "failed" }
}

/// Wrappers for the `{"data": …}` / `{"task": …}` envelopes.
struct DataEnvelope<T: Decodable>: Decodable { let data: T }
struct TaskEnvelope: Decodable { let task: TaskRecord }

public enum JSON {
    public static var decoder: JSONDecoder {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }
}
