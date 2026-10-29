// The six board subjects under their official names, in the order of the
// LLE Table of Specifications (docs/lle_tos.json, App\Support\LleTaxonomy).
// The server saves any older spelling ("Reference Services", "Library
// Management") under these names.
export const LIBRARY_SUBJECT_CATEGORIES = [
  'Cataloging and Classification',
  'Indexing and Abstracting',
  'Information Technology',
  'Library Organization and Management',
  'Reference, Bibliography and User Services',
  'Selection and Acquisition',
]

// The short name each subject goes by where space is tight.
export const SUBJECT_SHORT_NAMES = {
  'Cataloging and Classification': 'Cataloging',
  'Indexing and Abstracting': 'Indexing',
  'Information Technology': 'IT',
  'Library Organization and Management': 'Management',
  'Reference, Bibliography and User Services': 'Reference',
  'Selection and Acquisition': 'Selection',
}
