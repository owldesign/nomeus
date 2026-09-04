import Foundation
import ServiceManagement

/// SMAppService.mainApp — no helper, no Team ID needed. Only meaningful from inside Nomeus.app.
enum LaunchAtLogin {
    static var isEnabled: Bool {
        guard AppSettings.isBundled else { return false }
        return SMAppService.mainApp.status == .enabled
    }

    static func set(_ enabled: Bool) throws {
        guard AppSettings.isBundled else { return }
        if enabled {
            try SMAppService.mainApp.register()
        } else {
            try SMAppService.mainApp.unregister()
        }
    }
}
