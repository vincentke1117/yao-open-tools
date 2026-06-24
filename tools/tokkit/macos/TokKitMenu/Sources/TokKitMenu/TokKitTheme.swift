import SwiftUI

enum TokKitTheme {
    static let panelWidth: CGFloat = 418
    static let panelMaxHeight: CGFloat = 760

    static let panel = Color(hex: 0xFFFFFF)
    static let paper = Color(hex: 0xFAF9F5)
    static let paperStrong = Color(hex: 0xF3F0E8)
    static let line = Color(hex: 0xE7E2D7)
    static let lineSoft = Color(hex: 0xEFEBE2)
    static let ink = Color(hex: 0x141413)
    static let muted = Color(hex: 0x504E49)
    static let stone = Color(hex: 0x6B6A64)
    static let brand = Color(hex: 0x1B365D)
    static let brand2 = Color(hex: 0x2D5A8A)
    static let brandTint = Color(hex: 0xEEF2F7)
    static let warning = Color(hex: 0x8A5D2A)
}

extension Color {
    init(hex: UInt, opacity: Double = 1) {
        self.init(
            .sRGB,
            red: Double((hex >> 16) & 0xFF) / 255,
            green: Double((hex >> 8) & 0xFF) / 255,
            blue: Double(hex & 0xFF) / 255,
            opacity: opacity
        )
    }
}
