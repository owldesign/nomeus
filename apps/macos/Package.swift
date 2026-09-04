// swift-tools-version:5.10
import PackageDescription

// Nomeus.app — a menu bar shell over the Laravel API that Valet already serves at http://nomeus.test.
// NomeusCore has no UI dependencies so it can be tested with `swift test`; Nomeus is the SwiftUI app.
let package = Package(
    name: "Nomeus",
    platforms: [.macOS(.v14)],
    targets: [
        .target(name: "NomeusCore"),
        .executableTarget(
            name: "Nomeus",
            dependencies: ["NomeusCore"],
            linkerSettings: [
                .linkedFramework("SwiftUI"),
                .linkedFramework("WebKit"),
                .linkedFramework("UserNotifications"),
                .linkedFramework("ServiceManagement"),
            ]
        ),
        .testTarget(name: "NomeusCoreTests", dependencies: ["NomeusCore"]),
    ]
)
