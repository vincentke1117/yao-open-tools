// swift-tools-version: 6.0

import PackageDescription

let package = Package(
    name: "TokKitMenu",
    platforms: [
        .macOS(.v13)
    ],
    products: [
        .executable(name: "TokKitMenu", targets: ["TokKitMenu"])
    ],
    targets: [
        .executableTarget(
            name: "TokKitMenu"
        )
    ]
)
