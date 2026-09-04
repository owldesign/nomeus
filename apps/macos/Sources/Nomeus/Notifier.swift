import Foundation
import UserNotifications
import NomeusCore

/// "postgres stopped" when an instance goes running → not answering. Silent unless bundled:
/// UNUserNotificationCenter aborts the process when there's no bundle (i.e. under `swift run`).
final class Notifier: Notifying {
    private var authorized = false

    func requestAuthorizationIfBundled() {
        guard AppSettings.isBundled else { return }
        // The flag is read back from the settings rather than trusted from the callback: in the 10a walk the
        // callback could not be observed at all (the bundle's NSLog never reached the unified log), and the
        // settings query is what the Settings row shows, so both agree by construction.
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound]) { [weak self] granted, _ in
            if granted { self?.authorized = true }
        }
        Task { _ = await authorizationStatus() }
    }

    /// What macOS currently thinks, for Settings: the prompt is shown once and, if the app quits before it's
    /// answered, is not shown again — this is the only readout the user gets.
    func authorizationStatus() async -> String {
        guard AppSettings.isBundled else { return "available when running from Nomeus.app" }
        let settings = await UNUserNotificationCenter.current().notificationSettings()
        switch settings.authorizationStatus {
        case .authorized, .provisional, .ephemeral:
            authorized = true
            return "allowed"
        case .denied:
            authorized = false
            return "not allowed — System Settings → Notifications → Nomeus"
        case .notDetermined:
            authorized = false
            return "not asked yet"
        @unknown default:
            return "unknown"
        }
    }

    func notify(title: String, body: String) {
        guard AppSettings.isBundled, authorized else { return }
        let content = UNMutableNotificationContent()
        content.title = title
        content.body = body
        content.sound = .default
        let request = UNNotificationRequest(identifier: "nomeus.\(title).\(Date().timeIntervalSince1970)", content: content, trigger: nil)
        UNUserNotificationCenter.current().add(request)
    }
}
