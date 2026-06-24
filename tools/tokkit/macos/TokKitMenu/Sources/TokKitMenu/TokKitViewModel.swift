import Foundation

@MainActor
final class TokKitViewModel: ObservableObject {
    @Published private(set) var snapshot: TokKitSnapshot?
    @Published private(set) var isLoading = false
    @Published private(set) var isRefreshing = false
    @Published private(set) var trendWindow: TrendWindow = .seven
    @Published var errorMessage: String?

    var menuBarTitle: String {
        guard let total = snapshot?.range.byDate.reduce(0, { $0 + $1.totalTokens }), total > 0 else {
            return "TokKit"
        }
        return TokenFormat.compact(total)
    }

    func loadSnapshot(forceLoadingState: Bool = false) async {
        if isRefreshing {
            return
        }
        isLoading = snapshot == nil || forceLoadingState
        defer { isLoading = false }
        do {
            snapshot = try await TokKitCommandService.shared.snapshot(lastDays: trendWindow.days)
            errorMessage = nil
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func setTrendWindow(_ window: TrendWindow) async {
        guard trendWindow != window else { return }
        trendWindow = window
        await loadSnapshot(forceLoadingState: true)
    }

    func refresh() async {
        if isRefreshing {
            return
        }
        isRefreshing = true
        defer { isRefreshing = false }
        do {
            try await TokKitCommandService.shared.refresh()
            snapshot = try await TokKitCommandService.shared.snapshot(lastDays: trendWindow.days)
            errorMessage = nil
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    func openHTML() async {
        do {
            try await TokKitCommandService.shared.openHTML(lastDays: trendWindow.days)
            errorMessage = nil
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

enum TrendWindow: Int, CaseIterable, Identifiable {
    case seven = 7
    case thirty = 30

    var id: Int { rawValue }
    var days: Int { rawValue }
    var label: String { "\(rawValue)日" }
}

enum TokenFormat {
    static func compact(_ value: Int?) -> String {
        guard let value else { return "-" }
        let number = Double(value)
        if abs(number) >= 1_000_000_000 {
            return String(format: "%.2fB", number / 1_000_000_000)
        }
        if abs(number) >= 1_000_000 {
            return String(format: "%.2fM", number / 1_000_000)
        }
        if abs(number) >= 1_000 {
            return String(format: "%.1fK", number / 1_000)
        }
        return "\(value)"
    }

    static func integer(_ value: Int?) -> String {
        guard let value else { return "-" }
        return NumberFormatter.tokInteger.string(from: NSNumber(value: value)) ?? "\(value)"
    }

    static func money(_ value: Double?) -> String {
        guard let value else { return "-" }
        if abs(value) >= 100 {
            return String(format: "$%.0f", value)
        }
        return String(format: "$%.2f", value)
    }

    static func percent(_ value: Double?) -> String {
        guard let value else { return "-" }
        return String(format: "%.1f%%", value * 100)
    }
}

private extension NumberFormatter {
    static let tokInteger: NumberFormatter = {
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        formatter.maximumFractionDigits = 0
        return formatter
    }()
}
