import Foundation

public enum APIError: Error, LocalizedError, Equatable {
    case unreachable(String)
    case http(Int, String)
    case decoding(String)
    case taskFailed(String)
    /// nginx answered 3xx; the base URL is wrong (typically http for a site that is now https).
    case redirected(URL)

    public var errorDescription: String? {
        switch self {
        case .unreachable(let why): return "Nomeus is unreachable: \(why)"
        case .http(let code, let message): return "HTTP \(code): \(message)"
        case .decoding(let what): return "Unexpected response while decoding \(what)"
        case .taskFailed(let label): return "\(label) failed — see the Tasks page"
        case .redirected(let url): return "Redirected to \(url.absoluteString)"
        }
    }
}

/// Thin client over routes/api.php. Reads are synchronous on the PHP side; mutations answer 202 + task,
/// so `start/stop/restart` return the task and `wait(for:)` polls /api/tasks/{id} until it finishes.
public struct APIClient: Sendable {
    public let baseURL: URL
    private let transport: HTTPTransport
    /// Injected so tests don't sleep. Seconds.
    private let sleep: @Sendable (Double) async throws -> Void

    public init(
        baseURL: URL,
        transport: HTTPTransport = URLSessionTransport(),
        sleep: @escaping @Sendable (Double) async throws -> Void = { try await Task.sleep(for: .seconds($0)) }
    ) {
        self.baseURL = baseURL
        self.transport = transport
        self.sleep = sleep
    }

    /// Same transport and sleeper, a different origin. Used when a redirect reveals the real base.
    public func rebased(to url: URL) -> APIClient {
        APIClient(baseURL: url, transport: transport, sleep: sleep)
    }

    // MARK: reads

    public func status() async throws -> StatusSnapshot {
        try await get("/api/status", as: StatusSnapshot.self)
    }

    public func sites() async throws -> [Site] {
        try await get("/api/sites", as: DataEnvelope<[Site]>.self).data
    }

    public func services() async throws -> [Service] {
        try await get("/api/services", as: DataEnvelope<[Service]>.self).data
    }

    public func task(_ id: String) async throws -> TaskRecord {
        try await get("/api/tasks/\(id)", as: DataEnvelope<TaskRecord>.self).data
    }

    // MARK: service lifecycle (each is a detached task on the PHP side)

    public func start(service name: String) async throws -> TaskRecord { try await post("/api/services/\(name)/start") }
    public func stop(service name: String) async throws -> TaskRecord { try await post("/api/services/\(name)/stop") }
    public func restart(service name: String) async throws -> TaskRecord { try await post("/api/services/\(name)/restart") }

    /// Poll until the task leaves queued/running. Throws `.taskFailed` on a non-zero exit.
    public func wait(for task: TaskRecord, every interval: Double = 0.75, maxPolls: Int = 240) async throws -> TaskRecord {
        var current = task
        var polls = 0
        while !current.isFinished && polls < maxPolls {
            try await sleep(interval)
            current = try await self.task(task.id)
            polls += 1
        }
        if current.failed { throw APIError.taskFailed(current.label) }
        return current
    }

    // MARK: plumbing

    private func get<T: Decodable>(_ path: String, as type: T.Type) async throws -> T {
        var request = URLRequest(url: url(path))
        request.httpMethod = "GET"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        return try await decode(send(request), as: type, path: path)
    }

    private func post(_ path: String) async throws -> TaskRecord {
        var request = URLRequest(url: url(path))
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        // RequireNomeusHeader: every unsafe method must say "X-Nomeus: 1" or it's a 403. It's the API's
        // CSRF/DNS-rebinding guard (a custom header forces a CORS preflight that config/cors.php refuses).
        request.setValue("1", forHTTPHeaderField: "X-Nomeus")
        request.httpBody = Data("{}".utf8)
        return try await decode(send(request), as: TaskEnvelope.self, path: path).task
    }

    private func url(_ path: String) -> URL {
        // appending(path:) percent-encodes and keeps the leading slash sane against a host-only base.
        baseURL.appending(path: path.hasPrefix("/") ? String(path.dropFirst()) : path)
    }

    private func send(_ request: URLRequest) async throws -> Data {
        let data: Data
        let response: HTTPURLResponse
        do {
            (data, response) = try await transport.send(request)
        } catch let error as APIError {
            throw error
        } catch {
            throw APIError.unreachable(error.localizedDescription)
        }
        if (300..<400).contains(response.statusCode),
           let location = response.value(forHTTPHeaderField: "Location"),
           let target = URL(string: location, relativeTo: request.url)?.absoluteURL {
            throw APIError.redirected(target)
        }
        guard (200..<300).contains(response.statusCode) else {
            let message = (try? JSONDecoder().decode(ErrorBody.self, from: data))?.message
                ?? String(data: data.prefix(200), encoding: .utf8)
                ?? ""
            throw APIError.http(response.statusCode, message)
        }
        return data
    }

    private func decode<T: Decodable>(_ data: Data, as type: T.Type, path: String) throws -> T {
        do {
            return try JSON.decoder.decode(type, from: data)
        } catch {
            throw APIError.decoding("\(path): \(error)")
        }
    }

    private struct ErrorBody: Decodable { let message: String }
}
