import Foundation

/// The one seam between the client and the network. Tests hand in a fake; the app uses URLSession.
public protocol HTTPTransport: Sendable {
    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse)
}

/// URLSession with redirects *not* followed. Following them silently turns a POST into a GET (the RFC
/// behaviour for 301/302), which is a 405 on every task route the moment the site is https and the base
/// still says http. Instead the 3xx comes back as-is and APIClient reports where it pointed.
public struct URLSessionTransport: HTTPTransport {
    private let session: URLSession
    private let delegate = NoRedirect()

    public init(timeout: TimeInterval = 8) {
        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = timeout
        config.waitsForConnectivity = false
        session = URLSession(configuration: config)
    }

    public func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let (data, response) = try await session.data(for: request, delegate: delegate)
        guard let http = response as? HTTPURLResponse else {
            throw APIError.unreachable("non-HTTP response")
        }
        return (data, http)
    }

    private final class NoRedirect: NSObject, URLSessionTaskDelegate, @unchecked Sendable {
        func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse,
                        newRequest request: URLRequest) async -> URLRequest? {
            nil
        }
    }
}
