import XCTest

/// Nomeus.app's Info.plist is not code, but it is the one file that decides whether URLSession may talk
/// to http://nomeus.test at all — and `swift run` never reads it, so a regression here only shows in the bundle.
final class InfoPlistTests: XCTestCase {
    private func plist() throws -> [String: Any] {
        let url = URL(fileURLWithPath: #filePath)
            .deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent()
            .appending(path: "Resources/Info.plist")
        let data = try Data(contentsOf: url)
        return try XCTUnwrap(PropertyListSerialization.propertyList(from: data, format: nil) as? [String: Any])
    }

    func testATSAllowsPlainHttpToTheDefaultBase() throws {
        let ats = try XCTUnwrap(try plist()["NSAppTransportSecurity"] as? [String: Any])
        XCTAssertEqual(ats["NSAllowsArbitraryLoads"] as? Bool, true)
        // macOS ignores NSAllowsArbitraryLoads the moment one of the narrower keys is present, and
        // nomeus.test (dnsmasq → 127.0.0.1) is not "local" to ATS. Seen on the first bundled launch as
        // "App Transport Security policy requires the use of a secure connection" on every poll.
        for key in ["NSAllowsLocalNetworking", "NSAllowsArbitraryLoadsInWebContent", "NSAllowsArbitraryLoadsForMedia"] {
            XCTAssertNil(ats[key], "\(key) makes ATS ignore NSAllowsArbitraryLoads")
        }
    }
}
