import AppKit
import SwiftUI

struct TokKitPanel: View {
    @ObservedObject var model: TokKitViewModel
    private let timer = Timer.publish(every: 60, on: .main, in: .common).autoconnect()

    var body: some View {
        ScrollView {
            Group {
                if let snapshot = model.snapshot {
                    content(snapshot)
                } else {
                    loadingState
                }
            }
            .padding(18)
        }
        .frame(width: TokKitTheme.panelWidth, height: panelHeight)
        .background(TokKitTheme.panel)
        .onReceive(timer) { _ in
            Task { await model.loadSnapshot() }
        }
    }

    private var panelHeight: CGFloat {
        let visibleHeight = NSScreen.main?.visibleFrame.height ?? TokKitTheme.panelMaxHeight
        return min(TokKitTheme.panelMaxHeight, max(560, visibleHeight - 112))
    }

    private func content(_ snapshot: TokKitSnapshot) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            header(snapshot)
            chips(snapshot)
            summary(snapshot)
            trend(snapshot)
            detailTable(snapshot)
            splitPanels(snapshot)
            footer(snapshot)

            if let error = model.errorMessage {
                Text(error)
                    .font(.system(size: 11))
                    .foregroundStyle(.red)
                    .textSelection(.enabled)
            }
        }
    }

    private var loadingState: some View {
        VStack(spacing: 12) {
            ProgressView()
                .controlSize(.small)
            Text(model.errorMessage ?? "正在读取 TokKit 本地账本")
                .font(.system(size: 12))
                .foregroundStyle(TokKitTheme.stone)
                .multilineTextAlignment(.center)
                .textSelection(.enabled)
            Button("重新读取") {
                Task { await model.loadSnapshot() }
            }
            .buttonStyle(.borderedProminent)
            .tint(TokKitTheme.brand)
        }
        .frame(maxWidth: .infinity, minHeight: 240)
    }

    private func header(_ snapshot: TokKitSnapshot) -> some View {
        HStack(alignment: .top, spacing: 12) {
            HStack(spacing: 10) {
                Text("T")
                    .font(.custom("Songti SC", size: 20).weight(.medium))
                    .foregroundStyle(.white)
                    .frame(width: 34, height: 34)
                    .background(TokKitTheme.brand, in: RoundedRectangle(cornerRadius: 8))

                VStack(alignment: .leading, spacing: 3) {
                    Text("TokKit")
                        .font(.custom("Songti SC", size: 22).weight(.medium))
                        .foregroundStyle(TokKitTheme.ink)
                    Text("本地 AI Token 账本 · 截至 \(timeOnly(snapshot.generatedAt))")
                        .font(.system(size: 12))
                        .foregroundStyle(TokKitTheme.stone)
                }
            }

            Spacer()

            Button {
                Task { await model.refresh() }
            } label: {
                HStack(spacing: 6) {
                    if model.isRefreshing {
                        ProgressView()
                            .controlSize(.small)
                            .tint(.white)
                    } else {
                        Image(systemName: "arrow.clockwise")
                    }
                    Text(model.isRefreshing ? "更新中" : "更新")
                }
                .font(.system(size: 13, weight: .semibold))
                .foregroundStyle(.white)
                .frame(minWidth: 82, minHeight: 34)
            }
            .buttonStyle(.plain)
            .background(TokKitTheme.brand, in: RoundedRectangle(cornerRadius: 8))
            .disabled(model.isRefreshing)
        }
    }

    private func chips(_ snapshot: TokKitSnapshot) -> some View {
        FlowLayout(spacing: 6) {
            Chip(displayTimezone(snapshot.timezone), highlighted: true)
            Chip("SQLite 本地")
            Chip(snapshot.automation.launchdInstalled ? "launchd 已启用" : "launchd 未启用")
        }
        .padding(.top, 2)
    }

    private func summary(_ snapshot: TokKitSnapshot) -> some View {
        let today = snapshot.today.totals
        let budget = snapshot.budget.windows.first(where: { $0.window == "Today" })

        return Grid(horizontalSpacing: 10, verticalSpacing: 10) {
            GridRow {
                MetricCard(
                    title: "今日用量",
                    meta: "\(today.records) records",
                    value: TokenFormat.compact(today.totalTokens)
                )

                BudgetCard(window: budget)
            }
        }
    }

    private func trend(_ snapshot: TokKitSnapshot) -> some View {
        VStack(alignment: .leading, spacing: 9) {
            HStack(alignment: .center, spacing: 8) {
                Text("近 \(snapshot.range.rangeDays) 天趋势")
                    .font(.custom("Songti SC", size: 15).weight(.medium))
                    .foregroundStyle(TokKitTheme.ink)
                Spacer(minLength: 6)
                Text("\(TokenFormat.compact(totalTokens(snapshot.range.byDate))) tokens · \(TokenFormat.money(totalCost(snapshot.range.byDate)))")
                    .font(.system(size: 10, design: .monospaced))
                    .foregroundStyle(TokKitTheme.stone)
                    .lineLimit(1)
                    .minimumScaleFactor(0.82)
                TrendWindowToggle(
                    selection: model.trendWindow,
                    isLoading: model.isLoading,
                    onSelect: { window in
                        Task { await model.setTrendWindow(window) }
                    }
                )
            }
            TrendLineChart(rows: snapshot.range.byDate)
        }
        .panelStyle()
    }

    private func splitPanels(_ snapshot: TokKitSnapshot) -> some View {
        Grid(horizontalSpacing: 10, verticalSpacing: 10) {
            GridRow {
                RankPanel(
                    title: "终端",
                    note: "Top 4",
                    rows: Array(snapshot.range.byTerminal.prefix(4)).map { ($0.terminal ?? "unknown", $0.totalTokens) }
                )
                RankPanel(
                    title: "模型",
                    note: "Top 4",
                    rows: Array(snapshot.range.byModel.prefix(4)).map { ($0.modelLabel ?? "unknown", $0.totalTokens) }
                )
            }
        }
    }

    private func detailTable(_ snapshot: TokKitSnapshot) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .firstTextBaseline) {
                Text("近 7 日明细")
                    .font(.custom("Songti SC", size: 15).weight(.medium))
                    .foregroundStyle(TokKitTheme.ink)
                Spacer()
                Text("按日期倒序")
                    .font(.system(size: 10, design: .monospaced))
                    .foregroundStyle(TokKitTheme.stone)
            }
            .padding(12)

            Divider().overlay(TokKitTheme.lineSoft)

            VStack(spacing: 0) {
                HStack(spacing: 0) {
                    TableHeading("日期")
                    TableHeading("Tokens")
                    TableHeading("费用")
                    TableHeading("记录")
                }
                .background(TokKitTheme.paper)

                ForEach(detailRows(snapshot)) { row in
                    DetailTableRow(row: row, todayDate: snapshot.today.date)
                }
            }
        }
        .background(TokKitTheme.panel)
        .clipShape(RoundedRectangle(cornerRadius: 8))
        .overlay(RoundedRectangle(cornerRadius: 8).stroke(TokKitTheme.line, lineWidth: 1))
    }

    private func footer(_ snapshot: TokKitSnapshot) -> some View {
        VStack(spacing: 11) {
            Divider().overlay(TokKitTheme.lineSoft)

            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("数据源")
                        .font(.system(size: 11, weight: .medium))
                        .foregroundStyle(TokKitTheme.muted)
                    Text(snapshot.ledger.dbPath.replacingOccurrences(of: NSHomeDirectory(), with: "~"))
                        .font(.system(size: 10))
                        .foregroundStyle(TokKitTheme.stone)
                        .lineLimit(1)
                        .truncationMode(.middle)
                }

                Spacer()

                VStack(alignment: .trailing, spacing: 2) {
                    Text("覆盖口径")
                        .font(.system(size: 11, weight: .medium))
                        .foregroundStyle(TokKitTheme.muted)
                    Text("exact · partial · estimated")
                        .font(.system(size: 10))
                        .foregroundStyle(TokKitTheme.stone)
                }
            }

            HStack(spacing: 8) {
                Button {
                    Task { await model.openHTML() }
                } label: {
                    Label("打开 HTML", systemImage: "safari")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(TokKitSecondaryButtonStyle())

                Button {
                    NSApplication.shared.terminate(nil)
                } label: {
                    Label("退出", systemImage: "power")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(TokKitSecondaryButtonStyle())
            }
        }
        .font(.system(size: 11))
    }

    private func totalTokens(_ rows: [UsageRow]) -> Int {
        rows.reduce(0) { $0 + $1.totalTokens }
    }

    private func totalCost(_ rows: [UsageRow]) -> Double {
        rows.reduce(0) { $0 + ($1.billableCostUsd ?? 0) }
    }

    private func detailRows(_ snapshot: TokKitSnapshot) -> [UsageRow] {
        Array(snapshot.range.byDate.sorted(by: { ($0.localDate ?? "") > ($1.localDate ?? "") }).prefix(7))
    }

    private func timeOnly(_ raw: String) -> String {
        if let date = ISO8601DateFormatter().date(from: raw) {
            return DateFormatter.tokTime.string(from: date)
        }
        if let timePart = raw.split(separator: "T").dropFirst().first {
            return String(timePart.prefix(5))
        }
        return raw
    }

    private func displayTimezone(_ timezone: String) -> String {
        timezone == "Asia/Shanghai" ? "Asia/Beijing" : timezone
    }
}

private struct Chip: View {
    let text: String
    let highlighted: Bool

    init(_ text: String, highlighted: Bool = false) {
        self.text = text
        self.highlighted = highlighted
    }

    var body: some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold, design: .monospaced))
            .foregroundStyle(highlighted ? TokKitTheme.brand : TokKitTheme.muted)
            .padding(.horizontal, 8)
            .frame(height: 24)
            .background(highlighted ? TokKitTheme.brandTint : TokKitTheme.paperStrong, in: RoundedRectangle(cornerRadius: 5))
    }
}

private struct MetricCard: View {
    let title: String
    let meta: String
    let value: String

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text(title)
                Spacer()
                Text(meta)
            }
            .font(.system(size: 12))
            .foregroundStyle(TokKitTheme.stone)

            Text(value)
                .font(.custom("Songti SC", size: 28).weight(.medium))
                .foregroundStyle(TokKitTheme.brand)
                .fontDesign(.serif)
                .minimumScaleFactor(0.75)
                .lineLimit(1)
        }
        .frame(maxWidth: .infinity, minHeight: 88, alignment: .topLeading)
        .padding(12)
        .background(TokKitTheme.paper, in: RoundedRectangle(cornerRadius: 8))
        .overlay(RoundedRectangle(cornerRadius: 8).stroke(TokKitTheme.line, lineWidth: 1))
    }
}

private struct BudgetCard: View {
    let window: BudgetWindow?

    var body: some View {
        VStack(alignment: .leading, spacing: 7) {
            HStack {
                Text("今日预算")
                Spacer()
                Text(TokenFormat.money(window?.estBudget))
            }
            .font(.system(size: 12))
            .foregroundStyle(TokKitTheme.stone)

            HStack(alignment: .center) {
                VStack(alignment: .leading, spacing: 8) {
                    Text(window?.status == "ok" ? "ok" : (window?.status ?? "-"))
                        .font(.custom("Songti SC", size: 24).weight(.medium))
                        .foregroundStyle(window?.status == "over" ? TokKitTheme.warning : TokKitTheme.brand)
                    Text(remainingText)
                        .font(.system(size: 11, design: .monospaced))
                        .foregroundStyle(TokKitTheme.muted)
                }

                Spacer()
                BudgetRing(progress: window?.estPct)
            }
        }
        .frame(maxWidth: .infinity, minHeight: 88, alignment: .topLeading)
        .padding(12)
        .background(TokKitTheme.paper, in: RoundedRectangle(cornerRadius: 8))
        .overlay(RoundedRectangle(cornerRadius: 8).stroke(TokKitTheme.line, lineWidth: 1))
    }

    private var remainingText: String {
        guard let budget = window?.estBudget, let spent = window?.billableCostUsd else {
            return "未配置预算"
        }
        let remaining = budget - spent
        return "\(TokenFormat.money(abs(remaining))) \(remaining >= 0 ? "剩余" : "超出")"
    }
}

private struct SectionHeader: View {
    let title: String
    let note: String

    var body: some View {
        HStack(alignment: .firstTextBaseline) {
            Text(title)
                .font(.custom("Songti SC", size: 15).weight(.medium))
                .foregroundStyle(TokKitTheme.ink)
            Spacer()
            Text(note)
                .font(.system(size: 10, design: .monospaced))
                .foregroundStyle(TokKitTheme.stone)
        }
    }
}

private struct TrendWindowToggle: View {
    let selection: TrendWindow
    let isLoading: Bool
    let onSelect: (TrendWindow) -> Void

    var body: some View {
        HStack(spacing: 1) {
            ForEach(TrendWindow.allCases) { window in
                Button {
                    onSelect(window)
                } label: {
                    Text(window.label)
                        .font(.system(size: 10, weight: .semibold, design: .monospaced))
                        .foregroundStyle(selection == window ? .white : TokKitTheme.brand)
                        .frame(width: window == .seven ? 32 : 42, height: 22)
                }
                .buttonStyle(.plain)
                .background(selection == window ? TokKitTheme.brand : Color.clear, in: RoundedRectangle(cornerRadius: 5))
                .disabled(isLoading)
            }
        }
        .padding(2)
        .background(TokKitTheme.brandTint, in: RoundedRectangle(cornerRadius: 7))
    }
}

private struct RankPanel: View {
    let title: String
    let note: String
    let rows: [(String, Int)]

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            SectionHeader(title: title, note: note)

            Grid(horizontalSpacing: 8, verticalSpacing: 10) {
                ForEach(Array(rows.enumerated()), id: \.offset) { index, row in
                    RankedBar(
                        label: row.0,
                        value: row.1,
                        maxValue: maxValue,
                        softened: index > 0
                    )
                }
            }
        }
        .frame(maxWidth: .infinity, minHeight: 136, maxHeight: 136, alignment: .topLeading)
        .padding(11)
        .background(TokKitTheme.paper, in: RoundedRectangle(cornerRadius: 8))
        .overlay(RoundedRectangle(cornerRadius: 8).stroke(TokKitTheme.line, lineWidth: 1))
    }

    private var maxValue: Int {
        max(rows.map(\.1).max() ?? 0, 1)
    }
}

private struct DetailTableRow: View {
    let row: UsageRow
    let todayDate: String

    var body: some View {
        HStack(spacing: 0) {
            TableCell(shortDate(row.localDate), isToday: isToday)
            TableCell(TokenFormat.compact(row.totalTokens), isToday: isToday)
            TableCell(TokenFormat.money(row.billableCostUsd), isToday: isToday)
            TableCell(TokenFormat.integer(row.records), isToday: isToday)
        }
    }

    private var isToday: Bool {
        row.localDate == todayDate
    }

    private func shortDate(_ raw: String?) -> String {
        guard let raw else { return "-" }
        let parts = raw.split(separator: "-")
        guard parts.count == 3 else { return raw }
        return "\(Int(parts[1]) ?? 0)/\(Int(parts[2]) ?? 0)"
    }
}

private struct TableHeading: View {
    let text: String

    init(_ text: String) {
        self.text = text
    }

    var body: some View {
        Text(text)
            .font(.system(size: 11))
            .foregroundStyle(TokKitTheme.stone)
            .frame(maxWidth: .infinity, minHeight: 30, alignment: .center)
            .contentShape(Rectangle())
    }
}

private struct TableCell: View {
    let text: String
    let isToday: Bool

    init(_ text: String, isToday: Bool = false) {
        self.text = text
        self.isToday = isToday
    }

    var body: some View {
        Text(text)
            .font(.system(size: 11, weight: isToday ? .semibold : .regular, design: .monospaced))
            .foregroundStyle(isToday ? TokKitTheme.brand : TokKitTheme.muted)
            .frame(maxWidth: .infinity, minHeight: 29, alignment: .center)
            .contentShape(Rectangle())
            .background(isToday ? TokKitTheme.brandTint : TokKitTheme.panel)
    }
}

private struct TokKitSecondaryButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 12, weight: .medium))
            .foregroundStyle(TokKitTheme.brand)
            .padding(.horizontal, 10)
            .frame(height: 34)
            .background(configuration.isPressed ? TokKitTheme.brandTint.opacity(0.75) : TokKitTheme.brandTint)
            .clipShape(RoundedRectangle(cornerRadius: 8))
            .scaleEffect(configuration.isPressed ? 0.97 : 1)
    }
}

private extension View {
    func panelStyle() -> some View {
        self
            .padding(12)
            .background(TokKitTheme.panel, in: RoundedRectangle(cornerRadius: 8))
            .overlay(RoundedRectangle(cornerRadius: 8).stroke(TokKitTheme.line, lineWidth: 1))
    }
}

private struct FlowLayout<Content: View>: View {
    let spacing: CGFloat
    @ViewBuilder let content: Content

    var body: some View {
        HStack(spacing: spacing) {
            content
        }
    }
}

private extension DateFormatter {
    static let tokTime: DateFormatter = {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm"
        return formatter
    }()
}
