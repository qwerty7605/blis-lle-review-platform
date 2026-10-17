import { Bug, HelpCircle, Lightbulb, MessageSquare } from 'lucide-vue-next'

// The kinds of feedback, as the Settings card offers them.
export const feedbackTypes = [
  { value: 'problem', label: 'Report a Problem', short: 'Problem', hint: "Something isn't working", icon: Bug },
  { value: 'idea', label: 'Suggest an Idea', short: 'Idea', hint: 'A feature or an improvement', icon: Lightbulb },
  { value: 'question', label: 'Ask a Question', short: 'Question', hint: 'How something works', icon: HelpCircle },
  { value: 'other', label: 'Something Else', short: 'Other', hint: 'Anything else on your mind', icon: MessageSquare },
]

export function feedbackType(value) {
  return feedbackTypes.find((type) => type.value === value) ?? feedbackTypes[3]
}

// The usual five-point satisfaction scale.
export const satisfactionLabels = ['Very dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very satisfied']

// One status reads differently by kind: a problem is "fixed", an idea "done".
export function feedbackStatusLabel(item) {
  const problem = item?.type === 'problem'

  switch (item?.status) {
    case 'planned':
      return problem ? 'Being fixed' : 'With the developers'
    case 'done':
      return problem ? 'Fixed' : 'Done'
    case 'declined':
      return problem ? 'Not a problem' : "Won't do"
    case 'answered':
      return 'Answered'
    default:
      return problem && item?.sent_to_developers ? 'Sent to developers' : 'Waiting for review'
  }
}

export function feedbackStatusTone(status) {
  return { new: 'gold', planned: 'navy', done: 'success', answered: 'success', declined: 'neutral' }[status] ?? 'neutral'
}
