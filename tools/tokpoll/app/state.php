<?php
declare(strict_types=1);

/**
 * 组装前台需要的完整状态（首屏内联 + 轮询接口共用同一份结构）。
 */
function build_state(array $survey): array
{
    $questions = Survey::questions((int)$survey['id']);
    $voteSt    = Voter::voteStatus($survey);
    $submitSt  = Voter::submitStatus($survey);
    $open      = Survey::isOpen($survey);

    return [
        'survey' => [
            'id'            => (int)$survey['id'],
            'title'         => $survey['title'],
            'open'          => $open,
            'phase'         => Survey::phase($survey),
            'starts_at'     => $survey['starts_at'],
            'ends_at'       => $survey['ends_at'],
            'allow_submit'  => (int)$survey['allow_submit'],
            'need_review'   => (int)$survey['need_review'],
            'votes_per_day' => (int)$survey['votes_per_day'],
        ],
        'questions' => $questions,
        'my_votes'  => Voter::myVotes((int)$survey['id']),
        'quota'     => [
            'vote' => [
                'allowed' => $voteSt['allowed'], 'used' => $voteSt['used'],
                'quota'   => $voteSt['quota'],   'reason' => $voteSt['reason'],
            ],
            'submit' => [
                'allowed' => $submitSt['allowed'], 'used' => $submitSt['used'],
                'quota'   => $submitSt['quota'],   'reason' => $submitSt['reason'],
            ],
        ],
        'stats'   => Survey::stats((int)$survey['id']),
        'version' => Survey::listVersion((int)$survey['id']),
        'csrf'    => csrf_token(),
        'api'     => base_path() . '/api.php',
        'poll_ms' => (int)cfg('poll_interval_ms', 8000),
    ];
}
