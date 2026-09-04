import AppKit
import NomeusCore

/// The menu bar mark: the four-point star from site/favicon.svg (the same path the SPA's Led draws),
/// as a template image so it inverts with the menu bar. Health is carried by the rendering, not colour —
/// template images are monochrome: filled = ok, outline = degraded, struck = all down, faint outline = unreachable.
enum StarIcon {
    private static var cache: [String: NSImage] = [:]

    static func image(for health: Health) -> NSImage {
        let key = variant(for: health)
        if let cached = cache[key] { return cached }
        let image = draw(key)
        cache[key] = image
        return image
    }

    private static func variant(for health: Health) -> String {
        switch health {
        case .ok: return "filled"
        case .degraded: return "outline"
        case .down: return "struck"
        case .unreachable: return "faint"
        }
    }

    /// 18×18 pt canvas (the menu bar's comfortable size), star inscribed in 16×16 at 1pt inset.
    private static func draw(_ variant: String) -> NSImage {
        let size = NSSize(width: 18, height: 18)
        let image = NSImage(size: size, flipped: true) { _ in
            let star = starPath(in: NSRect(x: 1, y: 1, width: 16, height: 16))
            NSColor.black.setFill()
            NSColor.black.setStroke()
            switch variant {
            case "filled":
                star.fill()
            case "outline":
                star.lineWidth = 1.4
                star.stroke()
            case "struck":
                star.lineWidth = 1.4
                star.stroke()
                let slash = NSBezierPath()
                slash.move(to: NSPoint(x: 3, y: 15))
                slash.line(to: NSPoint(x: 15, y: 3))
                slash.lineWidth = 1.6
                slash.lineCapStyle = .round
                slash.stroke()
            default: // faint
                NSColor.black.withAlphaComponent(0.45).setStroke()
                star.lineWidth = 1.2
                star.stroke()
            }
            return true
        }
        image.isTemplate = true
        image.accessibilityDescription = variant
        return image
    }

    /// favicon.svg: `M8 0 C8.55 4.9 11.1 7.45 16 8 C11.1 8.55 8.55 11.1 8 16 C7.45 11.1 4.9 8.55 0 8 C4.9 7.45 7.45 4.9 8 0 Z`
    /// in a 16-unit box, scaled into `rect`.
    static func starPath(in rect: NSRect) -> NSBezierPath {
        let s = rect.width / 16
        func p(_ x: CGFloat, _ y: CGFloat) -> NSPoint { NSPoint(x: rect.minX + x * s, y: rect.minY + y * s) }
        let path = NSBezierPath()
        path.move(to: p(8, 0))
        path.curve(to: p(16, 8), controlPoint1: p(8.55, 4.9), controlPoint2: p(11.1, 7.45))
        path.curve(to: p(8, 16), controlPoint1: p(11.1, 8.55), controlPoint2: p(8.55, 11.1))
        path.curve(to: p(0, 8), controlPoint1: p(7.45, 11.1), controlPoint2: p(4.9, 8.55))
        path.curve(to: p(8, 0), controlPoint1: p(4.9, 7.45), controlPoint2: p(7.45, 4.9))
        path.close()
        return path
    }
}
