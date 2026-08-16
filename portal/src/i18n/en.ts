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
    forgotten: 'Forgotten your password? Please contact the family administrator.',
    failed: 'That username or password is not right. Please try again.',
    missing: 'Please fill in both fields.',
  },
  profile: {
    title: 'My profile',
    noRecord: {
      title: 'Your entry in the family tree is missing',
      body: 'Your account is not linked to a person in the family tree yet. The family administrator can set that up.',
    },
    readOnly: 'In this version you can view your details, but not yet change them.',
    openInWebtrees: 'Open the family tree and charts',
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
    directoryChange:
      'Only the family administrator can change this at the moment. Write to them if you would like it changed.',
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
    noEvents: 'No further details are recorded for this person.',
    sex: {
      M: 'male',
      F: 'female',
      X: 'other',
      U: 'unknown',
    },
  },
  error: {
    title: 'Something went wrong',
    retry: 'Try again',
    network: 'The portal could not reach the server. Please check your internet connection.',
    not_found: 'That entry was not found. It may no longer be shared with you.',
    not_configured:
      'The portal is not fully set up yet. Please contact the family administrator.',
    server_error: 'The server ran into a problem. Please try again later.',
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
