// For an administrator's teaching pages, which list every teacher's rooms,
// exams and Library files: who made a thing, and the list of people to
// filter by. A teacher sees only their own, so none of this shows for them.
export function overseesAll(user) {
  return String(user?.role ?? '').toLowerCase() === 'admin'
}

/** The distinct makers of a list of records carrying `creator: { id, name }`. */
export function ownersOf(records) {
  const owners = new Map()

  for (const record of records ?? []) {
    const creator = record?.creator
    if (creator?.id && !owners.has(creator.id)) owners.set(creator.id, { id: creator.id, name: creator.name ?? 'Unknown' })
  }

  return [...owners.values()].sort((left, right) => left.name.localeCompare(right.name))
}

export function isOwnedBy(record, ownerId) {
  return ownerId === '' || String(record?.creator?.id ?? record?.created_by ?? '') === String(ownerId)
}
