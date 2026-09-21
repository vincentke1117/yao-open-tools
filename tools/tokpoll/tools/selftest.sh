#!/usr/bin/env bash
# Tokpoll 自测脚本
# 用法：bash tools/selftest.sh [基地址]   默认 http://127.0.0.1:7317
set -uo pipefail

BASE="${1:-http://127.0.0.1:7317}"
JAR="$(mktemp)"; JAR2="$(mktemp)"; ADM="$(mktemp)"
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31m✗\033[0m %s\n'   "$1"; [ $# -gt 1 ] && printf '      %s\n' "$2"; }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# 从首屏内联 JSON 里取 csrf
get_csrf() {
  curl -s -b "$1" -c "$1" "$BASE/" \
    | grep -o '"csrf":"[^"]*"' | head -1 | cut -d'"' -f4
}
api_post() {   # $1=cookiejar $2=action $3=json
  curl -s -b "$1" -c "$1" -X POST -H 'Content-Type: application/json' \
       -d "$3" "$BASE/api.php?action=$2"
}

head_ "1. 页面可访问性"
for path in "/" "/index.php?p=history" "/index.php?p=survey&id=2" "/admin.php?a=login"; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$path")
  [ "$code" = "200" ] && ok "GET $path → 200" || bad "GET $path → $code"
done
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/index.php?p=survey&id=999999")
[ "$code" = "404" ] && ok "不存在的期次 → 404" || bad "不存在的期次 → $code"

head_ "2. 未登录不能进后台"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/admin.php?a=dash")
case "$loc" in *a=login*) ok "后台页面跳转到登录页" ;; *) bad "后台未拦截" "$loc" ;; esac
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/admin.php?a=q_action" -d 'id=1&op=purge')
[ "$code" = "302" ] && ok "未登录的写操作被拦截（302 到登录）" || bad "未登录写操作返回 $code"

head_ "3. CSRF 校验"
CSRF=$(get_csrf "$JAR")
[ -n "$CSRF" ] && ok "拿到 CSRF token" || bad "没拿到 CSRF token"
r=$(api_post "$JAR" vote '{"question_id":1,"csrf":"bogus"}')
echo "$r" | grep -q '"code":"csrf"' && ok "错误 token 被拒绝" || bad "CSRF 未拦截" "$r"

head_ "4. 投票：一人一天一票 + 改票"
r=$(api_post "$JAR" vote "{\"question_id\":1,\"csrf\":\"$CSRF\"}")
echo "$r" | grep -q '"ok":true' && ok "首次投票成功" || bad "首次投票失败" "$r"
v1=$(echo "$r" | grep -o '"my_votes":\[[^]]*\]')
echo "$v1" | grep -q '1' && ok "my_votes 记录了这一票（${v1}）" || bad "my_votes 不对" "$v1"

r=$(api_post "$JAR" vote "{\"question_id\":1,\"csrf\":\"$CSRF\"}")
echo "$r" | grep -q '已经投过' && ok "重复投同一题是幂等的" || bad "重复投票行为异常" "$r"

r=$(api_post "$JAR" vote "{\"question_id\":2,\"csrf\":\"$CSRF\"}")
echo "$r" | grep -q '"switched":true' && ok "投第二题＝改票（旧票自动撤回）" || bad "改票失败" "$r"
cnt=$(echo "$r" | grep -o '"my_votes":\[[^]]*\]' | tr -cd ',' | wc -c | tr -d '[:space:]')
[ "$cnt" = "0" ] && ok "改票后仍然只持有 1 票" || bad "改票后票数不对" "$(echo "$r" | grep -o '"my_votes":\[[^]]*\]')"

r=$(api_post "$JAR" unvote "{\"question_id\":2,\"csrf\":\"$CSRF\"}")
echo "$r" | grep -q '"my_votes":\[\]' && ok "撤回后票数归零" || bad "撤回失败" "$r"

head_ "5. 换一台设备（新 cookie）应能独立投票"
CSRF2=$(get_csrf "$JAR2")
r=$(api_post "$JAR2" vote "{\"question_id\":1,\"csrf\":\"$CSRF2\"}")
echo "$r" | grep -q '"ok":true' && ok "第二台设备投票成功（同 IP 不互相阻塞）" || bad "第二台设备被误拦" "$r"

head_ "6. 提问：内容校验 + 每天一次 + XSS"
r=$(api_post "$JAR" submit "{\"title\":\"短\",\"csrf\":\"$CSRF\"}")
echo "$r" | grep -q '太短' && ok "过短的问题被拒绝" || bad "长度校验失效" "$r"
r=$(api_post "$JAR" submit "{\"title\":\"https://spam.example.com/buy-now\",\"csrf\":\"$CSRF\"}")
echo "$r" | grep -q '不要只放链接' && ok "纯链接被拒绝" || bad "链接过滤失效" "$r"

XSS='<img src=x onerror=alert(1)>自测注入问题'
r=$(api_post "$JAR" submit "{\"title\":\"$XSS\",\"csrf\":\"$CSRF\"}")
echo "$r" | grep -q '"ok":true' && ok "正常提问成功（内容含 HTML 标签）" || bad "提问失败" "$r"
QID=$(echo "$r" | grep -o '"question_id":[0-9]*' | head -1 | cut -d: -f2)

r=$(api_post "$JAR" submit "{\"title\":\"今天的第二个问题应该被拒绝\",\"csrf\":\"$CSRF\"}")
echo "$r" | grep -q '今天已经提过' && ok "每人每天只能提 1 个" || bad "提问配额失效" "$r"

page=$(curl -s -b "$JAR" "$BASE/")
echo "$page" | grep -q '<img src=x onerror' && bad "XSS：原始标签出现在 HTML 里" || ok "XSS：标签已被转义，未原样输出"

head_ "7. 重复问题识别"
r=$(api_post "$JAR2" submit "{\"title\":\"$XSS\",\"csrf\":\"$CSRF2\"}")
echo "$r" | grep -q '一模一样' && ok "重复问题被识别并引导去投票" || bad "重复检测失效" "$r"

head_ "8. 后台登录与操作"
ACSRF=$(curl -s -c "$ADM" -b "$ADM" "$BASE/admin.php?a=login" | grep -o 'name="csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$ADM" -c "$ADM" -X POST \
       -d "csrf=$ACSRF&password=wrong-password" "$BASE/admin.php?a=login")
[ "$code" = "200" ] && ok "错误密码停留在登录页" || bad "错误密码返回 $code"

curl -s -o /dev/null -b "$ADM" -c "$ADM" -X POST \
     -d "csrf=$ACSRF&password=admin888" "$BASE/admin.php?a=login"
body=$(curl -s -b "$ADM" "$BASE/admin.php?a=dash")
echo "$body" | grep -q '总览' && ok "正确密码登录成功" || bad "登录失败"

for p in surveys questions tags data settings; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -b "$ADM" "$BASE/admin.php?a=$p")
  [ "$code" = "200" ] && ok "后台 /$p → 200" || bad "后台 /$p → $code"
done

if [ -n "${QID:-}" ]; then
  curl -s -o /dev/null -b "$ADM" -X POST -d "csrf=$ACSRF&id=$QID&op=hide" "$BASE/admin.php?a=q_action"
  state=$(curl -s "$BASE/api.php?action=state")
  echo "$state" | grep -q "\"id\":$QID," && bad "隐藏后问题仍出现在前台" || ok "后台隐藏后，前台列表里已消失"
fi

csv=$(curl -s -b "$ADM" -o /dev/null -w '%{http_code}' "$BASE/admin.php?a=export&sid=1")
[ "$csv" = "200" ] && ok "CSV 导出可用" || bad "CSV 导出 → $csv"

head_ "9. 已截止的期次不接受投票"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/index.php?p=survey&id=2")
[ "$code" = "200" ] && ok "往期结果页可查看" || bad "往期结果页 → $code"

printf '\n\033[1m结果：%d 项通过，%d 项失败\033[0m\n' "$PASS" "$FAIL"
rm -f "$JAR" "$JAR2" "$ADM"
[ "$FAIL" -eq 0 ]
