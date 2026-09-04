import Foundation

/// What the menu bar icon says. Derived from the cheap /api/status port probes, not launchd.
public enum Health: Equatable, Sendable {
    case unreachable          // API not answering: Valet/nginx/fpm down, or nomeus not installed
    case ok                   // every instance answering (or none defined)
    case degraded(down: Int)  // some instances down
    case down                 // instances defined, none answering

    public init(instances: [StatusSnapshot.Instance]) {
        guard !instances.isEmpty else { self = .ok; return }
        let down = instances.filter { !$0.running }.count
        switch down {
        case 0: self = .ok
        case instances.count: self = .down
        default: self = .degraded(down: down)
        }
    }

    /// SF Symbol for MenuBarExtra. Template rendering, so it follows the menu bar's light/dark.
    public var symbol: String {
        switch self {
        case .ok: return "circle.hexagongrid.fill"
        case .degraded: return "circle.hexagongrid"
        case .down: return "exclamationmark.triangle.fill"
        case .unreachable: return "bolt.slash"
        }
    }

    public var label: String {
        switch self {
        case .ok: return "All services running"
        case .degraded(let n): return "\(n) service\(n == 1 ? "" : "s") down"
        case .down: return "All services down"
        case .unreachable: return "Nomeus unreachable"
        }
    }
}

/// Names of instances that went running → stopped between two snapshots. Feeds notifications.
public func stoppedInstances(previous: [StatusSnapshot.Instance], current: [StatusSnapshot.Instance]) -> [String] {
    let wasRunning = Set(previous.filter(\.running).map(\.name))
    return current.filter { !$0.running && wasRunning.contains($0.name) }.map(\.name)
}
