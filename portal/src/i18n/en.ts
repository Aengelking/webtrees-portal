import type { Translations } from './de'

export const en: Translations = {
  app: {
    name: 'Family portal',
    skipToContent: 'Skip to content',
  },
  nav: {
    profile: 'My profile',
    members: 'Members',
    settings: 'Settings',
    main: 'Main navigation',
  },
  login: {
    title: 'Sign in',
    intro: 'Sign in with your username and password.',
    username: 'Username or email address',
    password: 'Password',
    submit: 'Sign in',
    submitting: 'One moment …',
    forgotten: 'Forgotten your password?',
    failed: 'That username or password is not right. Please try again.',
    missing: 'Please fill in both fields.',
  },
  password: {
    requestTitle: 'Reset your password',
    requestIntro:
      'Enter your email address. If there is an account for it, we will send you a link to set a new password.',
    email: 'Email address',
    send: 'Send the link',
    sending: 'Sending …',
    sent: {
      title: 'Please check your inbox',
      body:
        'If there is an account for that address, the link is on its way. It is valid for one hour. Do check your spam folder.',
    },
    backToLogin: 'Back to sign in',
    resetTitle: 'Choose a new password',
    resetIntro: 'Pick a password of at least eight characters.',
    newPassword: 'New password',
    repeatPassword: 'Repeat the password',
    save: 'Save the password',
    saving: 'Saving …',
    mismatch: 'The two passwords are not the same.',
    tooShort: 'The password must be at least eight characters long.',
    missingToken: {
      title: 'This link is incomplete',
      body: 'Please open the link from the email again, or ask for a new one.',
      action: 'Ask for a new link',
    },
    expired: 'This link has expired or has already been used. Please ask for a new one.',
  },
  profile: {
    title: 'My profile',
    noRecord: {
      title: 'Your entry in the family tree is missing',
      body: 'Your account is not linked to a person in the family tree yet. The family administrator can set that up.',
    },
    edit: 'Change my details',
    openInWebtrees: 'Open the family tree and charts',
    pending: {
      title: 'Your change is being reviewed',
      body:
        'Your details have been passed on. Until someone from the family administration approves them, you will keep seeing the previous version here.',
    },
  },
  edit: {
    title: 'Change my details',
    intro:
      'Your changes do not take effect straight away. The family administration looks at them and approves them.',
    section: {
      name: 'Name',
      birth: 'Birth',
      work: 'Work',
      contact: 'Contact',
    },
    givenNames: 'Given names',
    surname: 'Surname',
    birthDate: 'Date of birth',
    birthDateStored: 'Currently recorded: {{date}}. It stays as it is unless you choose a date here.',
    birthPlace: 'Place of birth',
    occupation: 'Occupation',
    address: 'Address',
    email: 'Email address',
    phone: 'Telephone',
    website: 'Website',
    contactHint: 'Your contact details are seen only by you and the family administration.',
    submit: 'Submit the change',
    submitting: 'Submitting …',
    cancel: 'Cancel',
    unchanged: 'You have not changed anything.',
    applied: {
      body: 'Your change has been applied and is live in the family tree.',
    },
    submitted: {
      title: 'Thank you — we have your change',
      body: 'It will be reviewed and then applied. There is nothing else for you to do.',
      action: 'Back to my profile',
    },
    blocked: {
      title: 'A change is still waiting',
      body:
        'You have already submitted a change that has not been approved yet. Please wait until it has been reviewed.',
    },
    locked: 'This record is locked and cannot be changed. Please contact the family administration.',
    noRecord: 'Your account is not linked to a person in the family tree yet.',
  },
  members: {
    title: 'Members',
    search: 'Search by name',
    searchHint: 'Only members who agreed to be listed are shown.',
    count_one: '{{count}} member',
    count_other: '{{count}} members',
    empty: {
      title: 'No members are visible yet',
      body: 'Members appear here once they agree to be listed in the directory.',
    },
    noResults: {
      title: 'Nothing found',
      body: 'Nobody was found for “{{query}}”. Try a shorter search.',
      action: 'Clear the search',
    },
    previous: 'Back',
    next: 'Next',
    page: 'Page {{page}} of {{pages}}',
    noRecord: 'No linked entry in the family tree',
  },
  member: {
    back: 'Back to the list',
    private: {
      title: 'No details visible',
      body: 'None of this member’s family tree data is shared with you.',
    },
  },
  settings: {
    title: 'Settings',
    language: 'Language',
    languageHint: 'Applies to this device.',
    account: 'Account',
    directory: 'Directory',
    directoryVisible: 'You are visible in the member directory.',
    directoryHidden: 'You are not visible in the member directory.',
    directoryToggle: 'Show me in the member directory',
    directoryExplain:
      'Other signed-in members will then see your name. You can switch this off again at any time.',
    displayName: 'Displayed name',
    displayNameHint: 'Leave empty to use your normal name.',
    save: 'Save',
    saving: 'Saving …',
    saved: 'Saved.',
    logout: 'Sign out',
    tree: 'Family tree',
  },
  individual: {
    born: 'Born',
    died: 'Died',
    events: 'Life events',
    parents: 'Parents',
    siblings: 'Brothers and sisters',
    spouses: 'Partners',
    children: 'Children',
    reference: 'Reference number in the family archive',
    relationship: 'To you: {{relationship}}',
    showAncestors: 'Show ancestors',
    photos: 'Photographs',
    photoUntitled: 'Untitled photograph',
    photoClose: 'Close',
    noEvents: 'No further details are recorded for this person.',
    sex: {
      M: 'male',
      F: 'female',
      X: 'other',
      U: 'unknown',
    },
  },
  person: {
    title: 'Person',
    backToProfile: 'Back to my profile',
  },
  ancestors: {
    title: 'Ancestors',
    line: {
      root: 'Starting point',
      paternal: "Father's line",
      maternal: "Mother's line",
    },
    none: {
      title: 'No ancestors recorded',
      body: 'The family tree records no parents for this person — or they are not shown to you.',
    },
    privacyNote:
      'Only people you are allowed to see are shown. Where a line ends, it may well continue in the family tree.',
  },
  error: {
    title: 'Something went wrong',
    retry: 'Try again',
    network: 'The portal could not reach the server. Please check your internet connection.',
    not_found: 'That entry was not found. It may no longer be shared with you.',
    not_configured:
      'The portal is not fully set up yet. Please contact the family administrator.',
    server_error: 'The server ran into a problem. Please try again later.',
    record_locked:
      'This record is locked and cannot be changed. Please contact the family administration.',
    change_pending:
      'An earlier change of yours is still waiting to be approved. Please wait until it has been reviewed.',
    no_linked_record: 'Your account is not linked to a person in the family tree yet.',
    unknown: 'Please try again.',
    pageNotFound: {
      title: 'This page does not exist',
      body: 'The address may have changed.',
      action: 'Go to my profile',
    },
  },
  common: {
    loading: 'Loading …',
  },
}
