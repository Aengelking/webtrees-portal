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
  invitation: {
    title: 'Welcome to the family',
    intro:
      'You have been invited to the member portal for “{{tree}}”. Set up your account here — it takes a minute.',
    invitedAs: 'This invitation is for:',
    realName: 'Your name',
    username: 'Username',
    usernameHint: 'You will sign in with this. At least three characters, no spaces and no “@”.',
    email: 'Email address',
    emailHint:
      'Where a reset link goes if you ever forget your password. It does not have to be the address the invitation was sent to.',
    passwordHint: 'At least eight characters.',
    save: 'Create my account',
    saving: 'Creating your account …',
    usernameTaken:
      'That username is already taken. Please choose another one — your invitation is still valid.',
    emailTaken:
      'There is already an account with that email address. Please use a different address, or sign in with the account you have.',
    badDetails: 'Please check what you entered. The server could not accept one of the details.',
    privacyNote:
      'In the portal you see only what has been released to you. Details about living people stay protected.',
    unusable: {
      title: 'This invitation is no longer valid',
      body:
        'The link has expired or has already been used. Please ask the family administrator for a new invitation.',
      action: 'Go to sign in',
    },
  },
  invite: {
    title: 'Invite somebody',
    intro:
      'You can set up portal access for your close family. Choose the person, get a link, and send it yourself — the way you would normally reach them.',
    chooseTitle: 'Who would you like to invite?',
    whoLegend: 'Choose a person',
    email: 'Email address (optional)',
    emailHint:
      'Only used to fill in their form for them. The link is not sent automatically — you send it yourself.',
    create: 'Create an invitation',
    creating: 'Creating …',
    remaining_one: 'You may have {{count}} more invitation outstanding.',
    remaining_other: 'You may have {{count}} more invitations outstanding.',
    pickSomebody: 'Please choose a person first.',
    refused:
      'You cannot invite this person. They may already have access, or already have been invited.',
    ready: {
      title: 'The invitation is ready',
      body:
        'Copy the link and send it to them — by message, email, however you like. It is shown this once and cannot be looked up again later. If you lose it, withdraw the invitation and create a new one.',
      label: 'Invitation link',
      done: 'I have copied it',
    },
    outstandingTitle: 'Your outstanding invitations',
    expires: 'Valid until {{date}}',
    withdraw: 'Withdraw',
    none: {
      title: 'Nobody to invite at the moment',
      body:
        'Your close relatives are already here, already invited, or recorded in the family tree as no longer living.',
    },
    quota: {
      title: 'You already have enough invitations outstanding',
      body: 'Withdraw one, or wait until it has been used — then you can send another.',
    },
    off: {
      title: 'Members cannot send invitations',
      body: 'In this family the administrators set up accounts. Please ask them.',
    },
    noRecord: {
      title: 'Your account is not linked yet',
      body:
        'While your account is not connected to anybody in the family tree, the portal does not know who your family is. Please contact the family administrators.',
    },
  },
  contact: {
    intro:
      'Enter what makes you reachable — and decide for each entry separately who may see it. Anything you leave empty is not shared.',
    kind: {
      email: 'Email address',
      phone: 'Telephone number',
      address: 'Postal address',
    },
    hint: {
      email: 'May be a different one from the address you sign in with.',
      phone: 'As you would say it to somebody on the telephone.',
      address: 'Street, postcode and town.',
    },
    audienceLegend: 'Who may see this?',
    audience: {
      nobody: 'Nobody',
      close_family: 'Only my close family',
      members: 'Every member of the portal',
    },
    save: 'Save contact details',
    saving: 'Saving …',
    saved: 'Your contact details are saved.',
    sharedTitle: 'Contact',
    off: {
      title: 'Contact details are switched off',
      body: 'This family does not share contact details through the portal.',
    },
  },
  message: {
    title: 'Message to {{name}}',
    subject: 'Subject',
    body: 'Your message',
    send: 'Send message',
    sending: 'Sending …',
    sent: 'Your message is on its way.',
    replyAddressNotice:
      'So that they can reply, your email address travels with the message as the reply address — even if you have not shared it above.',
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
    contact: 'My contact details',
    invite: 'Invite family',
    inviteBody:
      'You can set up portal access for your close family. You get a link and pass it on yourself.',
    inviteAction: 'Invite somebody',
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
    reference: 'If you report this, please quote this reference:',
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
