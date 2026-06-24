import Foundation

struct TokKitSnapshot: Decodable {
    let schemaVersion: Int
    let generatedAt: String
    let timezone: String
    let ledger: Ledger
    let automation: Automation
    let today: DailyReport
    let range: RangeReport
    let budget: BudgetReport
    let clients: ClientReport
    let commands: Commands

    struct Ledger: Decodable {
        let appHome: String
        let dbPath: String
        let dbExists: Bool
        let usageRecords: Int
        let latestRecord: String?
    }

    struct Automation: Decodable {
        let launchdInstalled: Bool
        let tokkitLabels: [String]
        let legacyTokstatLabels: [String]
    }

    struct Commands: Decodable {
        let snapshot: [String]
        let refresh: [String]
        let openHtml: [String]
    }
}

struct DailyReport: Decodable {
    let date: String
    let totals: UsageTotals
    let byHour: [UsageRow]
    let byTerminal: [UsageRow]
    let byModel: [UsageRow]
    let bySource: [UsageRow]
}

struct RangeReport: Decodable {
    let rangeDays: Int
    let byDate: [UsageRow]
    let byTerminal: [UsageRow]
    let byModel: [UsageRow]
    let bySource: [UsageRow]
}

struct BudgetReport: Decodable {
    let budgetPath: String
    let budgetExists: Bool
    let budgetLoaded: Bool
    let budgetError: String?
    let currency: String
    let windows: [BudgetWindow]
}

struct ClientReport: Decodable {
    let period: String
    let methodTotals: [ClientMethodTotal]
    let byDate: [ClientDateRow]
    let byClient: [ClientRow]
}

struct UsageTotals: Decodable {
    let inputTokens: Int?
    let outputTokens: Int?
    let cachedInputTokens: Int?
    let reasoningTokens: Int?
    let unsplitTokens: Int?
    let totalTokens: Int
    let credits: Double
    let records: Int
    let estimatedCostUsd: Double?
    let allocatedCostUsd: Double?
    let billableCostUsd: Double?
}

struct UsageRow: Decodable, Identifiable {
    let id = UUID()
    let localDate: String?
    let terminal: String?
    let modelLabel: String?
    let app: String?
    let source: String?
    let hourLabel: String?
    let inputTokens: Int?
    let outputTokens: Int?
    let cachedInputTokens: Int?
    let reasoningTokens: Int?
    let unsplitTokens: Int?
    let totalTokens: Int
    let credits: Double
    let records: Int
    let estimatedCostUsd: Double?
    let allocatedCostUsd: Double?
    let billableCostUsd: Double?
    let method: String?
    let billingMode: String?

    var displayLabel: String {
        localDate ?? terminal ?? modelLabel ?? app ?? source ?? hourLabel ?? "unknown"
    }

    private enum CodingKeys: String, CodingKey {
        case localDate
        case terminal
        case modelLabel
        case app
        case source
        case hourLabel
        case inputTokens
        case outputTokens
        case cachedInputTokens
        case reasoningTokens
        case unsplitTokens
        case totalTokens
        case credits
        case records
        case estimatedCostUsd
        case allocatedCostUsd
        case billableCostUsd
        case method
        case billingMode
    }
}

struct BudgetWindow: Decodable, Identifiable {
    let id = UUID()
    let window: String
    let startDate: String
    let endDate: String
    let totalTokens: Int
    let estimatedCostUsd: Double?
    let apiEstimatedCostUsd: Double?
    let billableCostUsd: Double?
    let estBudget: Double?
    let estPct: Double?
    let estPctLabel: String
    let credits: Double
    let creditsBudget: Double?
    let creditsPct: Double?
    let creditsPctLabel: String
    let status: String

    private enum CodingKeys: String, CodingKey {
        case window
        case startDate
        case endDate
        case totalTokens
        case estimatedCostUsd
        case apiEstimatedCostUsd
        case billableCostUsd
        case estBudget
        case estPct
        case estPctLabel
        case credits
        case creditsBudget
        case creditsPct
        case creditsPctLabel
        case status
    }
}

struct ClientMethodTotal: Decodable {
    let measurementMethod: String
    let totalTokens: Int
    let credits: Double
    let records: Int
}

struct ClientDateRow: Decodable {
    let localDate: String
    let exactTokens: Int
    let partialTokens: Int
    let estimatedTokens: Int
    let blendedTokens: Int
    let credits: Double
    let records: Int
}

struct ClientRow: Decodable, Identifiable {
    let id = UUID()
    let key: String
    let label: String
    let installed: Bool
    let coverage: String
    let notes: String
    let totalTokens: Int
    let credits: Double
    let records: Int
    let lastSeen: String?

    private enum CodingKeys: String, CodingKey {
        case key
        case label
        case installed
        case coverage
        case notes
        case totalTokens
        case credits
        case records
        case lastSeen
    }
}
