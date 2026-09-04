import SwiftUI
import NomeusCore

@main
struct NomeusApp: App {
    @NSApplicationDelegateAdaptor(AppDelegate.self) private var delegate

    var body: some Scene {
        MenuBarExtra {
            MenuBarView()
                .environment(delegate.model)
                .environment(delegate.poller)
        } label: {
            // Observed: the mark changes with health. Template image, so it follows the menu bar theme.
            Image(nsImage: StarIcon.image(for: delegate.model.health))
                .accessibilityLabel(delegate.model.health.label)
        }
        .menuBarExtraStyle(.window)

        Window("Nomeus", id: "dashboard") {
            DashboardView()
                .environment(delegate.model)
        }
        .defaultSize(width: 1180, height: 780)
        .windowResizability(.contentSize)
        .commands {
            CommandGroup(replacing: .newItem) {}
        }

        Settings {
            SettingsView(notifier: delegate.notifier)
                .environment(delegate.model)
                .environment(delegate.poller)
        }
    }
}

/// Owns the model and the poll loop. `swift run` (no bundle) and Nomeus.app both land here.
@MainActor
final class AppDelegate: NSObject, NSApplicationDelegate {
    let model: NomeusModel
    let poller: Poller
    let notifier = Notifier()

    override init() {
        model = NomeusModel(client: APIClient(baseURL: AppSettings.baseURL), notifier: notifier)
        poller = Poller(model: model)
        super.init()
        // nginx told us the real origin (http → https after `valet secure`): remember it across launches.
        model.onBaseChange = { AppSettings.baseURL = $0 }
    }

    func applicationDidFinishLaunching(_ notification: Notification) {
        // LSUIElement does this for the bundle; this covers `swift run` from a checkout.
        NSApp.setActivationPolicy(.accessory)
        notifier.requestAuthorizationIfBundled()
        poller.start()
    }

    func applicationWillTerminate(_ notification: Notification) {
        poller.stop()
    }
}

/// Status poll: fast while the menu is open, slow otherwise. One Task; interval read each tick.
@MainActor
@Observable
final class Poller {
    var menuOpen = false { didSet { if menuOpen { kick() } } }
    var slowInterval: Double = AppSettings.slowInterval
    let fastInterval: Double = 5

    @ObservationIgnored private let model: NomeusModel
    @ObservationIgnored private var loop: Task<Void, Never>?

    init(model: NomeusModel) { self.model = model }

    func start() {
        guard loop == nil else { return }
        loop = Task { [weak self] in
            while !Task.isCancelled {
                guard let self else { return }
                await self.model.refreshStatus()
                let interval = self.menuOpen ? self.fastInterval : self.slowInterval
                try? await Task.sleep(for: .seconds(interval))
            }
        }
    }

    func stop() { loop?.cancel(); loop = nil }

    /// Menu just opened: refresh everything now rather than waiting for the next tick.
    func kick() { Task { await model.refreshAll() } }

    /// Base URL changed in Settings: swap the client and refetch.
    func rebase(_ url: URL) {
        model.client = APIClient(baseURL: url)
        kick()
    }
}
