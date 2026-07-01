from pathlib import Path

from playwright.sync_api import expect, sync_playwright


BASE_URL = "http://127.0.0.1:5178"
OUTPUT_DIR = Path("test-results")


def assert_no_horizontal_overflow(page):
    overflow = page.evaluate(
        "() => document.documentElement.scrollWidth - window.innerWidth"
    )
    assert overflow <= 1, f"Horizontal overflow detected: {overflow}px"


with sync_playwright() as p:
    OUTPUT_DIR.mkdir(exist_ok=True)
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1000})
    page.goto(BASE_URL)
    page.wait_for_load_state("networkidle")

    expect(page.get_by_role("heading", name="有理数百问百答")).to_be_visible()
    expect(page.get_by_text("第 1 / 100 题")).to_be_visible()
    expect(page.locator("#question-title").get_by_text("____ 既不是正数，也不是负数。")).to_be_visible()
    expect(page.locator(".coach")).to_have_count(0)
    assert_no_horizontal_overflow(page)

    page.get_by_label("答案").fill("1")
    page.get_by_role("button", name="提交").click()
    expect(page.get_by_role("dialog").get_by_text("没关系，再想一想")).to_be_visible()
    expect(page.get_by_text("先看空格前后的限定词，抓住概念再作答。")).to_be_visible()
    expect(page.get_by_text("回答错误，已触发第 1 次提醒")).to_be_visible()
    expect(page.locator(".dialog-layer")).to_have_css("place-items", "center")
    expect(page.locator(".coach")).to_have_count(0)

    page.get_by_role("button", name="关闭").click()
    page.get_by_role("button", name="重置").click()
    page.get_by_label("答案").fill("0")
    page.get_by_role("button", name="提交").click()
    expect(page.get_by_role("dialog").get_by_text("太棒了，答对啦！")).to_be_visible()
    expect(page.get_by_text("这道题判断得很稳，给直播间同学们一个满分示范。")).to_be_visible()
    expect(page.get_by_text("回答正确，获得满分")).to_be_visible()
    expect(page.locator(".coach")).to_have_count(0)
    expect(page.get_by_label("总积分").get_by_text("100")).to_be_visible()
    page.wait_for_timeout(250)
    page.screenshot(path=str(OUTPUT_DIR / "desktop.png"), full_page=True)

    page.set_viewport_size({"width": 375, "height": 900})
    page.reload()
    page.wait_for_load_state("networkidle")
    assert_no_horizontal_overflow(page)
    expect(page.get_by_text("有理数百问百答")).to_be_visible()
    page.screenshot(path=str(OUTPUT_DIR / "mobile.png"), full_page=True)

    browser.close()
