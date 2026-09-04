import SwiftUI
import WebKit
import NomeusCore

/// The React SPA that Valet already serves, in a window. Nothing is bundled; it's just http://nomeus.test.
struct DashboardView: View {
    @Environment(NomeusModel.self) private var model

    var body: some View {
        Group {
            if model.health == .unreachable {
                ContentUnavailableView {
                    Label("Nomeus unreachable", systemImage: "bolt.slash")
                } description: {
                    Text("Can't load \(model.dashboardURL.absoluteString). Run `nomeus doctor` from a terminal.")
                } actions: {
                    Button("Retry") { Task { await model.refreshAll() } }
                }
            } else {
                WebView(url: model.dashboardURL)
            }
        }
        .frame(minWidth: 900, minHeight: 600)
    }
}

struct WebView: NSViewRepresentable {
    let url: URL

    func makeCoordinator() -> Coordinator { Coordinator(homeHost: url.host()) }

    func makeNSView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.websiteDataStore = .default()   // keep the SPA's localStorage between launches
        let view = WKWebView(frame: .zero, configuration: config)
        view.navigationDelegate = context.coordinator
        view.uiDelegate = context.coordinator
        view.allowsBackForwardNavigationGestures = true
        view.load(URLRequest(url: url))
        return view
    }

    func updateNSView(_ view: WKWebView, context: Context) {
        // Only reload when the base changes (Settings), not on every state tick.
        if context.coordinator.homeHost != url.host() {
            context.coordinator.homeHost = url.host()
            view.load(URLRequest(url: url))
        }
    }

    final class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate {
        var homeHost: String?
        init(homeHost: String?) { self.homeHost = homeHost }

        /// Links off the dashboard host (sites, docs, mailpit) go to the system browser; the SPA stays in the window.
        func webView(_ webView: WKWebView, decidePolicyFor action: WKNavigationAction) async -> WKNavigationActionPolicy {
            guard let target = action.request.url, action.navigationType == .linkActivated else { return .allow }
            if target.host() != homeHost, ["http", "https"].contains(target.scheme ?? "") {
                NSWorkspace.shared.open(target)
                return .cancel
            }
            return .allow
        }

        /// target=_blank: no child web views; hand the URL to the browser.
        func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
                     for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
            if let target = navigationAction.request.url { NSWorkspace.shared.open(target) }
            return nil
        }
    }
}

struct SettingsView: View {
    let notifier: Notifier
    @Environment(Poller.self) private var poller
    @State private var baseURL = AppSettings.baseURL.absoluteString
    @State private var slow = AppSettings.slowInterval
    @State private var launchAtLogin = LaunchAtLogin.isEnabled
    @State private var notifications = "…"
    @State private var message: String?

    var body: some View {
        Form {
            Section("Connection") {
                TextField("Dashboard URL", text: $baseURL)
                    .onSubmit(applyBaseURL)
                Text("Where Valet serves nomeus. Usually http://nomeus.test; https if you ran `valet secure nomeus`.")
                    .font(.caption).foregroundStyle(.secondary)
                Button("Apply") { applyBaseURL() }
            }
            Section("Polling") {
                Stepper(value: $slow, in: 5...300, step: 5) {
                    Text("Check services every \(Int(slow))s when the menu is closed")
                }
                .onChange(of: slow) { _, value in
                    AppSettings.slowInterval = value
                    poller.slowInterval = value
                }
            }
            Section("System") {
                Toggle("Launch at login", isOn: $launchAtLogin)
                    .disabled(!AppSettings.isBundled)
                    .onChange(of: launchAtLogin) { _, on in
                        do { try LaunchAtLogin.set(on) ; message = nil }
                        catch { message = error.localizedDescription; launchAtLogin = !on }
                    }
                if !AppSettings.isBundled {
                    Text("Available when running from Nomeus.app (scripts/bundle.sh), not `swift run`.")
                        .font(.caption).foregroundStyle(.secondary)
                }
                // macOS asks once, on first launch; this row is how you find out what it recorded.
                LabeledContent("Notifications", value: notifications)
                    .accessibilityLabel("Notifications: \(notifications)")
            }
            .task { notifications = await notifier.authorizationStatus() }
            if let message {
                Text(message).font(.caption).foregroundStyle(.red)
            }
        }
        .formStyle(.grouped)
        .frame(width: 440)
        .padding(.bottom, 8)
    }

    private func applyBaseURL() {
        guard let url = URL(string: baseURL.trimmingCharacters(in: .whitespaces)), url.host() != nil else {
            message = "That isn't a URL with a host."
            return
        }
        AppSettings.baseURL = url
        poller.rebase(url)
        message = nil
    }
}
