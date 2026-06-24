import Foundation

struct TokKitCommandError: LocalizedError {
    let command: [String]
    let status: Int32
    let output: String
    let errorOutput: String

    var errorDescription: String? {
        let renderedCommand = command.joined(separator: " ")
        let detail = errorOutput.isEmpty ? output : errorOutput
        return "\(renderedCommand) failed with status \(status). \(detail)"
    }
}

final class TokKitCommandService: @unchecked Sendable {
    static let shared = TokKitCommandService()

    private let decoder: JSONDecoder

    private init() {
        decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
    }

    func snapshot(lastDays: Int) async throws -> TokKitSnapshot {
        let data = try await runTok(arguments: ["snapshot", "--json", "--last", String(lastDays)])
        return try decoder.decode(TokKitSnapshot.self, from: data)
    }

    func refresh() async throws {
        _ = try await runTok(arguments: ["scan", "all"])
    }

    func openHTML(lastDays: Int) async throws {
        _ = try await runTok(arguments: ["html", "last", String(lastDays), "open"])
    }

    private func runTok(arguments: [String]) async throws -> Data {
        try await Task.detached(priority: .utility) {
            let command = self.command(for: arguments)
            let process = Process()
            process.executableURL = URL(fileURLWithPath: command.executable)
            process.arguments = command.arguments
            process.environment = command.environment

            let stdout = PipeCollector()
            let stderr = PipeCollector()
            process.standardOutput = stdout.pipe
            process.standardError = stderr.pipe

            stdout.start()
            stderr.start()
            try process.run()
            process.waitUntilExit()

            let output = stdout.finish()
            let errorOutput = stderr.finish()

            guard process.terminationStatus == 0 else {
                throw TokKitCommandError(
                    command: [command.executable] + command.arguments,
                    status: process.terminationStatus,
                    output: String(data: output, encoding: .utf8) ?? "",
                    errorOutput: String(data: errorOutput, encoding: .utf8) ?? ""
                )
            }

            return output
        }.value
    }

    private func command(for tokArguments: [String]) -> TokProcessCommand {
        var environment = ProcessInfo.processInfo.environment
        environment["PATH"] = [
            "/opt/homebrew/bin",
            "/usr/local/bin",
            "/usr/bin",
            "/bin",
            "/usr/sbin",
            "/sbin"
        ].joined(separator: ":")

        if let root = bundledTokKitRoot(),
           FileManager.default.fileExists(atPath: root.appendingPathComponent("src/tokkit/cli.py").path) {
            let existingPythonPath = environment["PYTHONPATH"].map { ":\($0)" } ?? ""
            environment["PYTHONPATH"] = root.appendingPathComponent("src").path + existingPythonPath
            return TokProcessCommand(
                executable: "/usr/bin/env",
                arguments: ["python3", "-m", "tokkit.tok"] + tokArguments,
                environment: environment
            )
        }

        if let explicitTok = environment["TOKKIT_TOK_PATH"],
           FileManager.default.isExecutableFile(atPath: explicitTok) {
            return TokProcessCommand(executable: explicitTok, arguments: tokArguments, environment: environment)
        }

        return TokProcessCommand(
            executable: "/usr/bin/env",
            arguments: ["tok"] + tokArguments,
            environment: environment
        )
    }

    private func bundledTokKitRoot() -> URL? {
        guard let url = Bundle.main.url(forResource: "tokkit-root", withExtension: "txt"),
              let contents = try? String(contentsOf: url, encoding: .utf8) else {
            return nil
        }
        let path = contents.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !path.isEmpty else {
            return nil
        }
        return URL(fileURLWithPath: path, isDirectory: true)
    }
}

private struct TokProcessCommand {
    let executable: String
    let arguments: [String]
    let environment: [String: String]
}

private final class PipeCollector: @unchecked Sendable {
    let pipe = Pipe()

    private let lock = NSLock()
    private var data = Data()

    func start() {
        pipe.fileHandleForReading.readabilityHandler = { [weak self] handle in
            let chunk = handle.availableData
            guard !chunk.isEmpty else { return }
            self?.append(chunk)
        }
    }

    func finish() -> Data {
        pipe.fileHandleForReading.readabilityHandler = nil
        append(pipe.fileHandleForReading.readDataToEndOfFile())

        lock.lock()
        defer { lock.unlock() }
        return data
    }

    private func append(_ chunk: Data) {
        guard !chunk.isEmpty else { return }
        lock.lock()
        data.append(chunk)
        lock.unlock()
    }
}
