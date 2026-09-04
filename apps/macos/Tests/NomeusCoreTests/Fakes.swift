import Foundation
import XCTest
@testable import NomeusCore

/// Route table keyed by "METHOD /path". A handler runs per call, so tests can script sequences
/// (task queued → running → done). Records every request. Nothing here touches the network.
final class FakeTransport: HTTPTransport, @unchecked Sendable {
    typealias Handler = (URLRequest) -> (Int, String)

    private let lock = NSLock()
    private var routes: [String: Handler] = [:]
    private var redirects: [String: String] = [:]
    /// "METHOD /path" per call — what most tests assert on.
    private(set) var requests: [String] = []
    /// Full URLs per call — for tests about which origin was hit.
    private(set) var urls: [String] = []
    var failWith: Error?

    /// Keys are "METHOD /path", or "METHOD scheme://host/path" to pin an origin (checked first).
    func on(_ key: String, _ handler: @escaping Handler) { lock.withLock { routes[key] = handler } }
    func on(_ key: String, status: Int = 200, body: String) { on(key) { _ in (status, body) } }
    /// nginx-style 301 with a Location header, no body.
    func redirect(_ key: String, to location: String) { lock.withLock { redirects[key] = location } }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let method = request.httpMethod ?? "GET"
        let path = request.url?.path() ?? ""
        let origin = "\(request.url?.scheme ?? "")://\(request.url?.host() ?? "")"
        let keys = ["\(method) \(origin)\(path)", "\(method) \(path)"]
        lock.withLock { requests.append(keys[1]); urls.append(request.url?.absoluteString ?? "") }
        if let failWith { throw failWith }
        // Mirror RequireNomeusHeader: unsafe methods without the header are a 403 on the real API.
        if !["GET", "HEAD", "OPTIONS"].contains(method), request.value(forHTTPHeaderField: "X-Nomeus") != "1" {
            return (Data(#"{"message":"Missing X-Nomeus header."}"#.utf8), response(request, 403))
        }
        if let location = lock.withLock({ keys.compactMap { redirects[$0] }.first }) {
            return (Data(), response(request, 301, headers: ["Location": location]))
        }
        guard let handler = lock.withLock({ keys.compactMap { routes[$0] }.first }) else {
            return (Data(#"{"message":"No route"}"#.utf8), response(request, 404))
        }
        let (status, body) = handler(request)
        return (Data(body.utf8), response(request, status))
    }

    private func response(_ request: URLRequest, _ status: Int, headers: [String: String] = [:]) -> HTTPURLResponse {
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: "HTTP/1.1",
                        headerFields: headers.merging(["Content-Type": "application/json"]) { a, _ in a })!
    }
}

final class RecordingNotifier: Notifying {
    var sent: [(title: String, body: String)] = []
    func notify(title: String, body: String) { sent.append((title, body)) }
}

enum Fixture {
    static let base = URL(string: "http://nomeus.test")!

    static func client(_ transport: FakeTransport) -> APIClient {
        APIClient(baseURL: base, transport: transport, sleep: { _ in })
    }

    static func status(instances: [(String, String, Int, Bool)] = [("pg", "postgresql", 5432, true), ("redis", "redis", 6379, true)]) -> String {
        let list = instances.map { #"{"name":"\#($0.0)","type":"\#($0.1)","port":\#($0.2),"running":\#($0.3)}"# }.joined(separator: ",")
        return """
        {"nomeus":{"version":"2.1.0","home":"/Users/x/.nomeus/app","config_path":"/Users/x/.nomeus/config.json","config_exists":true,"code_dir":"/Users/x/Code"},
         "valet":{"installed":true,"version":"4.8.0","tld":"test","loopback":"127.0.0.1","paths":["/Users/x/Code"],"bin":"/opt/homebrew/bin/valet","trusted":true},
         "php":{"global":"8.4","installed":["8.3","8.4"]},
         "services":{"nginx":true,"dnsmasq":true,"php_fpm":["8.4"],"mailpit":true},
         "dashboard":{"url":"http://nomeus.test","linked":true},
         "instances":[\(list)]}
        """
    }

    static let sites = """
    {"data":[
      {"name":"nomeus","host":"nomeus.test","url":"http://nomeus.test","type":"linked","path":"/Users/x/.nomeus/app","secured":false,"php":null,"laravel":true,"manifest":false,"nginx_conf":null},
      {"name":"shop","host":"shop.test","url":"https://shop.test","type":"parked","path":"/Users/x/Code/shop","secured":true,"php":"8.3","laravel":true,"manifest":true,"nginx_conf":"/Users/x/.config/valet/Nginx/shop.test"},
      {"name":"vite","host":"vite.test","url":"http://vite.test","type":"proxy","path":"http://127.0.0.1:5173","secured":false,"php":null,"laravel":false,"manifest":false,"nginx_conf":"/Users/x/.config/valet/Nginx/vite.test"}
    ]}
    """

    static func services(pgRunning: Bool = true) -> String {
        """
        {"data":[
          {"name":"pg","type":"postgresql","formula":"postgresql@17","version":"17.6","port":5432,"dir":"/Users/x/.nomeus/services/pg","created_at":"2026-01-01T00:00:00+00:00","options":{},
           "status":{"running":\(pgRunning),"loaded":true,"pid":\(pgRunning ? "4242" : "null"),"last_exit":0,"crashing":false,"disabled":false,"installed":true},
           "env":{"DB_CONNECTION":"pgsql","DB_PORT":"5432"}},
          {"name":"redis","type":"redis","formula":"redis","version":"8.2.1","port":6379,"dir":"/Users/x/.nomeus/services/redis","created_at":"2026-01-01T00:00:00+00:00","options":{},
           "status":{"running":true,"loaded":true,"pid":4243,"last_exit":0,"crashing":false,"disabled":false,"installed":true},
           "env":{"REDIS_PORT":"6379"}}
        ]}
        """
    }

    static func task(_ id: String = "t1", status: String, exit: Int? = nil, label: String = "services:stop pg") -> String {
        """
        {"id":"\(id)","label":"\(label)","argv":["services:stop","pg"],"cwd":"/Users/x/.nomeus/app","status":"\(status)","exit_code":\(exit.map(String.init) ?? "null"),"created_at":"2026-01-01T00:00:00+00:00","started_at":null,"finished_at":null,"timeout":120}
        """
    }
}
