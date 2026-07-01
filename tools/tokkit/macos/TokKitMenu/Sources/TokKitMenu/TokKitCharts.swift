import SwiftUI

struct TrendLineChart: View {
    let rows: [UsageRow]

    private var orderedRows: [UsageRow] {
        rows.sorted { ($0.localDate ?? "") < ($1.localDate ?? "") }
    }

    var body: some View {
        GeometryReader { proxy in
            let size = proxy.size
            let values = orderedRows.map(\.totalTokens)
            let maxValue = max(values.max() ?? 0, 1)
            let points = orderedRows.enumerated().map { index, row in
                let x = xPosition(index: index, count: orderedRows.count, width: size.width)
                let y = yPosition(value: row.totalTokens, maxValue: maxValue, height: size.height)
                return CGPoint(x: x, y: y)
            }

            ZStack {
                VStack(spacing: 0) {
                    ForEach(0..<4, id: \.self) { _ in
                        Rectangle()
                            .fill(TokKitTheme.lineSoft)
                            .frame(height: 1)
                        Spacer()
                    }
                }

                if points.count > 1 {
                    Path { path in
                        path.move(to: CGPoint(x: points[0].x, y: size.height - 14))
                        for point in points {
                            path.addLine(to: point)
                        }
                        path.addLine(to: CGPoint(x: points[points.count - 1].x, y: size.height - 14))
                        path.closeSubpath()
                    }
                    .fill(TokKitTheme.brandTint)

                    Path { path in
                        path.move(to: points[0])
                        for point in points.dropFirst() {
                            path.addLine(to: point)
                        }
                    }
                    .stroke(TokKitTheme.brand, style: StrokeStyle(lineWidth: 2.4, lineCap: .round, lineJoin: .round))
                }

                ForEach(Array(points.enumerated()), id: \.offset) { index, point in
                    if shouldShowAnchor(index: index, count: points.count) {
                        Circle()
                            .foregroundColor(TokKitTheme.panel)
                            .overlay(Circle().stroke(TokKitTheme.brand, lineWidth: 2))
                            .frame(width: 7, height: 7)
                            .position(point)
                    }
                }
            }
        }
        .frame(height: 126)
        .overlay(alignment: .bottom) {
            GeometryReader { proxy in
                ZStack(alignment: .topLeading) {
                    ForEach(axisLabels(width: proxy.size.width)) { label in
                        Text(label.text)
                            .font(.system(size: 10, design: .monospaced))
                            .foregroundStyle(TokKitTheme.stone)
                            .lineLimit(1)
                            .minimumScaleFactor(0.9)
                            .frame(width: 44, alignment: .center)
                            .position(x: label.x, y: 10)
                    }
                }
            }
            .frame(height: 20)
            .offset(y: 18)
        }
        .padding(.bottom, 18)
    }

    private func axisLabels(width: CGFloat) -> [TrendAxisLabel] {
        let count = orderedRows.count
        return orderedRows.enumerated().compactMap { index, row in
            let label = axisLabel(for: row, index: index, count: count)
            guard !label.isEmpty else { return nil }
            let rawX = xPosition(index: index, count: count, width: width)
            let x = min(max(rawX, 22), max(width - 22, 22))
            return TrendAxisLabel(id: index, text: label, x: x)
        }
    }

    private func shouldShowAnchor(index: Int, count: Int) -> Bool {
        guard count > 10 else { return true }
        let stride = max((count - 1) / 4, 1)
        return index == 0 || index == count - 1 || (index % stride == 0 && index < count - stride / 2)
    }

    private func xPosition(index: Int, count: Int, width: CGFloat) -> CGFloat {
        guard count > 1 else { return width / 2 }
        return CGFloat(index) / CGFloat(count - 1) * width
    }

    private func yPosition(value: Int, maxValue: Int, height: CGFloat) -> CGFloat {
        let usableHeight = max(height - 24, 1)
        let ratio = CGFloat(value) / CGFloat(maxValue)
        return 8 + usableHeight * (1 - ratio)
    }

    private func axisLabel(for row: UsageRow, index: Int, count: Int) -> String {
        guard count > 10 else { return shortDate(row.localDate) }
        if shouldShowAnchor(index: index, count: count) {
            return shortDate(row.localDate)
        }
        return ""
    }

    private func shortDate(_ raw: String?) -> String {
        guard let raw else { return "-" }
        let parts = raw.split(separator: "-")
        guard parts.count == 3 else { return raw }
        return "\(Int(parts[1]) ?? 0)/\(Int(parts[2]) ?? 0)"
    }
}

private struct TrendAxisLabel: Identifiable {
    let id: Int
    let text: String
    let x: CGFloat
}

struct BudgetRing: View {
    let progress: Double?

    var body: some View {
        let clamped = min(max(progress ?? 0, 0), 1)
        ZStack {
            Circle()
                .stroke(TokKitTheme.line, lineWidth: 4)
            Circle()
                .trim(from: 0, to: clamped)
                .stroke(TokKitTheme.brand, style: StrokeStyle(lineWidth: 4, lineCap: .round))
                .rotationEffect(.degrees(-90))
            Text(TokenFormat.percent(progress))
                .font(.system(size: 10, weight: .bold, design: .monospaced))
                .foregroundStyle(TokKitTheme.brand)
        }
        .frame(width: 50, height: 50)
    }
}

struct RankedBar: View {
    let label: String
    let value: Int
    let maxValue: Int
    let softened: Bool

    var body: some View {
        GridRow {
            Text(label)
                .lineLimit(1)
                .truncationMode(.tail)
                .foregroundStyle(TokKitTheme.muted)
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(TokKitTheme.line)
                    Capsule()
                        .fill(softened ? TokKitTheme.brand2.opacity(0.42) : TokKitTheme.brand)
                        .frame(width: max(fillWidth(totalWidth: proxy.size.width), 4))
                }
            }
            .frame(height: 7)
            Text(TokenFormat.compact(value))
                .font(.system(size: 11, design: .monospaced))
                .foregroundStyle(TokKitTheme.stone)
                .frame(width: 48, alignment: .trailing)
        }
        .font(.system(size: 11))
    }

    private func fillWidth(totalWidth: CGFloat) -> CGFloat {
        guard maxValue > 0 else { return 0 }
        return totalWidth * CGFloat(value) / CGFloat(maxValue)
    }
}
