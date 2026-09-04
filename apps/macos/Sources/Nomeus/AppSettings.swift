import Foundation

/// `defaults write dev.nomeus.app baseURL http://nomeus.test` etc. Read on launch and from Settings.
enum AppSettings {
    static let defaultBaseURL = URL(string: "http://nomeus.test")!
    private static let defaults = UserDefaults.standard

    static var baseURL: URL {
        get {
            defaults.string(forKey: "baseURL").flatMap(URL.init(string:)) ?? defaultBaseURL
        }
        set { defaults.set(newValue.absoluteString, forKey: "baseURL") }
    }

    /// Seconds between /api/status polls while the menu is closed.
    static var slowInterval: Double {
        get { max(5, defaults.object(forKey: "slowInterval") as? Double ?? 30) }
        set { defaults.set(newValue, forKey: "slowInterval") }
    }

    /// UNUserNotificationCenter and SMAppService abort when the process isn't inside a .app bundle
    /// (that's what `swift run` is). Everything else works unbundled.
    static var isBundled: Bool {
        Bundle.main.bundleURL.pathExtension == "app" && Bundle.main.bundleIdentifier != nil
    }
}
