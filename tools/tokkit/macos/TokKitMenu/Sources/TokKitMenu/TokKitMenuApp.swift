import SwiftUI

@main
struct TokKitMenuApp: App {
    @StateObject private var model = TokKitViewModel()

    var body: some Scene {
        MenuBarExtra {
            TokKitPanel(model: model)
                .frame(width: TokKitTheme.panelWidth)
        } label: {
            Label {
                Text(model.menuBarTitle)
                    .font(.system(size: 12, weight: .medium, design: .monospaced))
            } icon: {
                Text("T")
                    .font(.system(size: 10, weight: .bold, design: .rounded))
                    .foregroundStyle(.primary)
            }
        }
        .menuBarExtraStyle(.window)
    }
}
