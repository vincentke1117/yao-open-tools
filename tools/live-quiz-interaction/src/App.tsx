import {
  ArrowLeft,
  ArrowRight,
  Check,
  RotateCcw,
  Send,
  Trophy,
  Volume2,
  X,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import './App.css'
import { rationalQuestions } from './data/rationalQuestions'
import type { QuizFeedback } from './domain/types'
import {
  createQuizState,
  getScoreSummary,
  goToQuestion,
  submitAnswer,
} from './domain/quizEngine'
import { playResultSound } from './utils/sound'

function App() {
  const [quizState, setQuizState] = useState(() =>
    createQuizState(rationalQuestions),
  )
  const [answer, setAnswer] = useState('')
  const [resultDialog, setResultDialog] = useState<QuizFeedback | null>(null)
  const question = quizState.questions[quizState.currentIndex]
  const attempt = question?.id ? quizState.attempts[question.id] : undefined
  const summary = useMemo(() => getScoreSummary(quizState), [quizState])
  const progress = Math.round(
    ((summary.correct + summary.incorrect) / summary.total) * 100,
  )
  const canAnswer = attempt?.status === 'unanswered'

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!answer.trim() || !canAnswer) {
      return
    }

    const nextState = submitAnswer(quizState, answer)
    setQuizState(nextState)
    setResultDialog(nextState.feedback)
    setAnswer('')

    if (nextState.feedback.kind !== 'idle') {
      playResultSound(
        nextState.feedback.kind === 'correct' ? 'correct' : nextState.feedback.kind,
      )
    }
  }

  const jumpTo = (index: number) => {
    setQuizState((current) => goToQuestion(current, index))
    setResultDialog(null)
    setAnswer('')
  }

  const reset = () => {
    setQuizState(createQuizState(rationalQuestions))
    setResultDialog(null)
    setAnswer('')
  }

  return (
    <main className="page">
      {resultDialog && resultDialog.kind !== 'idle' ? (
        <ResultDialog
          feedback={resultDialog}
          onClose={() => setResultDialog(null)}
        />
      ) : null}

      <header className="masthead">
        <div>
          <p className="kicker">有理数经典 100 题</p>
          <h1>有理数百问百答</h1>
        </div>
        <div className="score" aria-label="总积分">
          <Trophy aria-hidden="true" size={16} />
          <strong>{summary.score}</strong>
          <span>分</span>
        </div>
      </header>

      <section className="paper" aria-labelledby="question-title">
        <div className="meta-line">
          <span>
            第 {quizState.currentIndex + 1} / {summary.total} 题
          </span>
          <span>
            对 {summary.correct} · 错 {summary.incorrect}
          </span>
        </div>

        <div className="progress" aria-label={`完成进度 ${progress}%`}>
          <span style={{ inlineSize: `${progress}%` }} />
        </div>

        <h2 id="question-title">
          {question.prompt.split('\n').map((line) => (
            <span key={line}>{line}</span>
          ))}
        </h2>

        <form className="answer-form" onSubmit={handleSubmit}>
          <label htmlFor="answer">答案</label>
          <div className="answer-row">
            <input
              id="answer"
              value={answer}
              onChange={(event) => setAnswer(event.target.value)}
              disabled={!canAnswer}
              autoComplete="off"
              placeholder={canAnswer ? '输入填空答案' : '本题已结束'}
            />
            <button type="submit" disabled={!canAnswer}>
              <Send aria-hidden="true" size={15} />
              提交
            </button>
          </div>
        </form>

        <nav className="actions" aria-label="题目操作">
          <button type="button" onClick={() => jumpTo(quizState.currentIndex - 1)}>
            <ArrowLeft aria-hidden="true" size={15} />
            上一题
          </button>
          <button type="button" onClick={reset}>
            <RotateCcw aria-hidden="true" size={15} />
            重置
          </button>
          <button type="button" onClick={() => jumpTo(quizState.currentIndex + 1)}>
            下一题
            <ArrowRight aria-hidden="true" size={15} />
          </button>
        </nav>
      </section>
    </main>
  )
}

function ResultDialog({
  feedback,
  onClose,
}: {
  feedback: QuizFeedback
  onClose: () => void
}) {
  const correct = feedback.kind === 'correct'
  const copy = getDialogCopy(feedback)

  return (
    <div className="dialog-layer" role="dialog" aria-live="assertive">
      <div className={`result-dialog ${correct ? 'is-correct' : 'is-wrong'}`}>
        <button type="button" className="dialog-close" onClick={onClose}>
          关闭
        </button>
        <div className="dialog-icon" aria-hidden="true">
          {correct ? <Check size={22} /> : <X size={22} />}
        </div>
        <div className="dialog-copy">
          <span>{copy.eyebrow}</span>
          <strong>{copy.title}</strong>
          <p>{copy.body}</p>
          <small>{copy.detail}</small>
        </div>
        <div className="dialog-footer">
          <span className="dialog-points">
            {formatPoints(feedback.pointsDelta)}
            <small>积分变化</small>
          </span>
          <span className="dialog-sound">
            <Volume2 aria-hidden="true" size={18} />
            已播放音效
          </span>
        </div>
      </div>
    </div>
  )
}

function getDialogCopy(feedback: QuizFeedback) {
  if (feedback.kind === 'correct') {
    return {
      eyebrow: '回答正确',
      title: '太棒了，答对啦！',
      body: '这道题判断得很稳，给直播间同学们一个满分示范。',
      detail: feedback.message,
    }
  }

  if (feedback.kind === 'locked') {
    return {
      eyebrow: '公布答案',
      title: '这题先记住正确答案',
      body: `正确答案是：${feedback.correctAnswer}。带大家把关键概念再过一遍。`,
      detail: feedback.message,
    }
  }

  return {
    eyebrow: '再试一次',
    title: '没关系，再想一想',
    body: '先看空格前后的限定词，抓住概念再作答。',
    detail: feedback.message,
  }
}

function formatPoints(points: number) {
  if (points > 0) {
    return `+${points}`
  }
  return String(points)
}

export default App
