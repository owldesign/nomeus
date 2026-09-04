import SwiftUI
import NomeusCore

struct MenuBarView: View {
    @Environment(NomeusModel.self) private var model
    @Environment(Poller.self) private var poller
    @Environment(\.openWindow) private var openWindow

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            header
            Divider()
            if model.health == .unreachable {
                unreachable
            } else {
                servicesSection
                Divider()
                sitesSection
            }
            if let error = model.lastError, model.health != .unreachable {
                Divider()
                HStack(alignment: .top, spacing: 8) {
                    Text(error)
                        .font(.caption)
                        .foregroundStyle(.red)
                        .lineLimit(3)
                        .textSelection(.enabled)
                    Spacer()
                    Button { model.dismissError() } label: { Image(systemName: "xmark.circle.fill") }
                        .buttonStyle(.borderless).controlSize(.small).foregroundStyle(.secondary)
                        .help("Dismiss")
                }
                .padding(.horizontal, 12).padding(.vertical, 8)
            }
            Divider()
            footer
        }
        .frame(width: 340)
        .onAppear { poller.menuOpen = true }
        .onDisappear { poller.menuOpen = false }
    }

    /// The brand gold (`--lantern`, #e3b341) for the mark when everything is running.
    private let lantern = Color(red: 0xe3 / 255, green: 0xb3 / 255, blue: 0x41 / 255)

    // MARK: sections

    private var header: some View {
        HStack {
            Image(nsImage: StarIcon.image(for: model.health))
                .foregroundStyle(model.health == .ok ? lantern : .secondary)
            VStack(alignment: .leading, spacing: 1) {
                Text("Nomeus").font(.headline)
                Text(model.health.label).font(.caption).foregroundStyle(.secondary)
            }
            Spacer()
            if let version = model.version {
                Text("v\(version)").font(.caption).foregroundStyle(.tertiary)
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 10)
    }

    private var unreachable: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Can't reach \(model.client.baseURL.host() ?? model.client.baseURL.absoluteString).")
                .font(.callout)
            Text("Valet's nginx or php-fpm may be down, or nomeus isn't linked. From a terminal: `nomeus doctor`.")
                .font(.caption).foregroundStyle(.secondary)
            if let error = model.lastError {
                Text(error).font(.caption2).foregroundStyle(.tertiary).lineLimit(2)
            }
            Button("Retry") { poller.kick() }
                .controlSize(.small)
                .padding(.top, 2)
        }
        .padding(12)
    }

    private var servicesSection: some View {
        VStack(alignment: .leading, spacing: 0) {
            sectionTitle("Services", count: model.services.count)
            if model.services.isEmpty {
                Text(model.status?.instances.isEmpty == false ? "Loading…" : "No instances. Create one from the dashboard or `nomeus services:create`.")
                    .font(.caption).foregroundStyle(.secondary)
                    .padding(.horizontal, 12).padding(.bottom, 8)
            }
            ForEach(model.services) { service in
                ServiceRow(service: service)
            }
        }
        .padding(.bottom, 4)
    }

    private var sitesSection: some View {
        VStack(alignment: .leading, spacing: 0) {
            sectionTitle("Sites", count: model.sites.count)
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    ForEach(model.sites) { site in
                        SiteRow(site: site)
                    }
                }
            }
            .frame(maxHeight: 220)
        }
        .padding(.bottom, 4)
    }

    private var footer: some View {
        HStack(spacing: 8) {
            Button {
                openWindow(id: "dashboard")
                NSApp.activate(ignoringOtherApps: true)
            } label: {
                Label("Dashboard", systemImage: "rectangle.on.rectangle")
            }
            .keyboardShortcut("d")

            Button {
                NSWorkspace.shared.open(model.dashboardURL)
            } label: {
                Label("In browser", systemImage: "safari")
            }

            Spacer()

            SettingsLink {
                Image(systemName: "gearshape")
            }
            .help("Settings")

            Button {
                NSApp.terminate(nil)
            } label: {
                Image(systemName: "power")
            }
            .help("Quit Nomeus")
            .keyboardShortcut("q")
        }
        .buttonStyle(.borderless)
        .controlSize(.small)
        .padding(.horizontal, 12).padding(.vertical, 8)
    }

    private func sectionTitle(_ title: String, count: Int) -> some View {
        HStack {
            Text(title.uppercased()).font(.caption2.weight(.semibold)).foregroundStyle(.secondary)
            Text("\(count)").font(.caption2).foregroundStyle(.tertiary)
            Spacer()
        }
        .padding(.horizontal, 12).padding(.top, 8).padding(.bottom, 4)
    }
}

private struct ServiceRow: View {
    @Environment(NomeusModel.self) private var model
    let service: Service

    private var busy: Bool { model.busy.contains(service.name) }

    var body: some View {
        HStack(spacing: 8) {
            Circle()
                .fill(color)
                .frame(width: 8, height: 8)
                .help(stateText)
            VStack(alignment: .leading, spacing: 0) {
                Text(service.name).font(.callout)
                Text("\(service.type) \(service.version) · :\(String(service.port))")  // String(): Text would group the digits (":9,912")
                    .font(.caption2).foregroundStyle(.secondary)
            }
            Spacer()
            if busy {
                ProgressView().controlSize(.mini)
            } else if service.status.running {
                iconButton("arrow.clockwise", help: "Restart") { Task { await model.restart(service.name) } }
                iconButton("stop.fill", help: "Stop") { Task { await model.stop(service.name) } }
            } else {
                iconButton("play.fill", help: "Start") { Task { await model.start(service.name) } }
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 5)
        .contentShape(Rectangle())
        .contextMenu {
            Button("Copy port") { copy("\(service.port)") }
            Button("Copy name") { copy(service.name) }
        }
    }

    private var color: Color {
        if service.status.crashing { return .orange }
        if service.status.running { return .green }
        if !service.status.installed { return .gray }
        return .red
    }

    private var stateText: String {
        if service.status.crashing { return "Crash-looping under launchd" }
        if service.status.running { return "Running, pid \(service.status.pid.map(String.init) ?? "?")" }
        if !service.status.installed { return "Formula not installed" }
        return service.status.loaded ? "Loaded, not answering" : "Stopped"
    }

    private func iconButton(_ symbol: String, help: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) { Image(systemName: symbol) }
            .buttonStyle(.borderless)
            .controlSize(.small)
            .help(help)
    }

    private func copy(_ text: String) {
        NSPasteboard.general.clearContents()
        NSPasteboard.general.setString(text, forType: .string)
    }
}

private struct SiteRow: View {
    let site: Site

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: site.secured ? "lock.fill" : "globe")
                .font(.caption)
                .foregroundStyle(site.secured ? .green : .secondary)
                .frame(width: 12)
            VStack(alignment: .leading, spacing: 0) {
                Text(site.host).font(.callout)
                HStack(spacing: 4) {
                    Text(site.type)
                    if let php = site.php { Text("· php \(php)") }
                    if site.laravel { Text("· laravel") }
                }
                .font(.caption2).foregroundStyle(.secondary)
            }
            Spacer()
            Button { NSWorkspace.shared.open(URL(string: site.url)!) } label: { Image(systemName: "arrow.up.right.square") }
                .buttonStyle(.borderless).controlSize(.small).help("Open \(site.url)")
            if site.type != "proxy" {
                Button { NSWorkspace.shared.selectFile(nil, inFileViewerRootedAtPath: site.path) } label: { Image(systemName: "folder") }
                    .buttonStyle(.borderless).controlSize(.small).help("Reveal in Finder")
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 4)
        .contentShape(Rectangle())
        .contextMenu {
            Button("Copy URL") { copy(site.url) }
            if site.type != "proxy" { Button("Copy path") { copy(site.path) } }
        }
    }

    private func copy(_ text: String) {
        NSPasteboard.general.clearContents()
        NSPasteboard.general.setString(text, forType: .string)
    }
}
