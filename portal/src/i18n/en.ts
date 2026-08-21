import type { Translations } from './de'

export const en: Translations = {
  app: {
    name: 'Sack Family App',
    skipToContent: 'Skip to content',
    offline: 'No internet connection. Nothing new can be loaded right now.',
  },
  nav: {
    profile: 'My profile',
    contacts: 'Contacts',
    messages: 'Messages',
    badge: {
      unread: '{{count}} unread',
      connections_one: '{{count}} connection request',
      connections_other: '{{count}} connection requests',
    },
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
      connections: 'Only my contacts',
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
  messages: {
    title: 'Messages',
    unread: 'unread',
    markUnread: 'Mark as unread',
    reply: 'Reply',
    replyAddressNotice:
      'So that you can be answered, your email address travels with the reply as the sender address — even if you have not shared it in your contact details.',
    replyLabel: 'Your reply',
    replyCancel: 'Cancel',
    replySend: 'Send reply',
    replySending: 'Sending …',
    replySent: 'Your reply is on its way. No copy is kept here.',
    replyImpossible:
      'This message cannot be answered here — the sender’s address does not belong to an account in the family tree.',
    delete: 'Delete',
    none: {
      title: 'No messages',
      body: 'Messages from other members or from the family administrators appear here.',
    },
    note:
      'This is your webtrees mailbox — the same messages you see there. Anything you delete here is deleted there too.',
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
  contacts: {
    title: 'My contacts',
    intro:
      'The people you have connected with. A connection is always made by two, and you can end it at any time.',
    find: 'Find somebody in the portal',
    findBody:
      'Everybody who agreed to be listed. Search for a name, or leave the field empty to see them all.',
    findLabel: 'Name',
    findAction: 'Search the directory',
    showCode: 'Connect in person',
    codeBody:
      'Show this code on your screen. The other person points their phone camera at it, and the two of you are connected. The code lasts {{count}} minutes.',
    codeShow: 'Show my code',
    codeRenew: 'Make a new code',
    codeHide: 'Stop this code working',
    codeHidden: 'That code no longer works. You can make a new one whenever you like.',
    codeValid: 'Works for about another {{count}} minutes. After that you need a new code.',
    codeAlt: 'QR code that lets somebody connect with you',
    byReference: 'Connect using an SB number',
    referenceBody:
      'The SB number is shown in the portal under the person’s name, for example “10/1335.21”. Pick the branch — the part before the slash — and type the rest. They receive a request and decide for themselves.',
    branchLabel: 'Branch',
    branchNone: 'No branch',
    branchOption: 'Branch {{branch}}',
    referenceLabel: 'Number',
    referenceHint: 'The part after the slash. A full stop or a comma makes no difference.',
    referencePlaceholder: '1335.21',
    referencePreview: 'Looking for:',
    ask: 'Send request',
    askThis: 'Connect',
    asking: 'One moment …',
    requested: 'Your request has reached {{name}}. Once they confirm it, they appear here.',
    connected: 'You are now connected with {{name}}.',
    incoming: 'Requests to you',
    outgoing: 'Your requests',
    waiting: 'Waiting for an answer.',
    asksYou: 'would like to connect with you.',
    asksYouAs: 'would like to connect with you — in the family tree: {{name}}.',
    accept: 'Accept',
    decline: 'Decline',
    withdraw: 'Withdraw',
    disconnect: 'End connection',
    sure: 'Really end the connection with {{name}}?',
    sureYes: 'Yes, end it',
    sureNo: 'Cancel',
    list: 'Connected',
    noRecord: 'No linked record in the family tree',
    withMember: 'Connection',
    state: {
      none: 'You are not connected with this person yet.',
      requested: 'Your request is on its way and is waiting for an answer.',
      incoming: '{{name}} would like to connect with you.',
      connected: 'You are connected.',
    },
    none: {
      title: 'No contacts yet',
      body: 'Connect at the next family gathering using the code above — or with an SB number, if somebody has given you theirs.',
    },
    off: {
      title: 'Connections are switched off',
      body: 'This family does not make new connections through the portal. You can still see and end the ones you have.',
    },
  },
  connect: {
    title: 'Connect',
    intro:
      'You have opened a connection code. If you go on, you and the person who showed it will be connected.',
    connect: 'Connect now',
    connecting: 'One moment …',
    done: 'You are now connected with {{name}}.',
    toContacts: 'To my contacts',
    missing: {
      title: 'That link is incomplete',
      body: 'Please scan the code again. If that does not work, ask for a new code to be shown.',
    },
  },
  members: {
    back: 'Back to contacts',
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
    connectWith: 'Connect with {{name}}',
    acceptFrom: 'Accept the request from {{name}}',
    state: {
      requested: 'Requested',
      connected: 'Connected',
    },
    noRecord: 'No linked entry in the family tree',
  },
  member: {
    back: 'Back to the list',
    private: {
      title: 'No details visible',
      body: 'None of this member’s family tree data is shared with you.',
    },
  },
  install: {
    title: 'Install the portal',
    body:
      'You can put the Sack family app on your home screen. It then opens with one tap, without an address bar and without hunting for it in the browser.',
    action: 'Add to home screen',
    apple: 'Tap the share icon at the bottom, then “Add to Home Screen”.',
  },
  settings: {
    contacts: 'My contacts',
    contactsBody:
      'Connect with individual members — with a QR code at a family gathering, or using an SB number. Your contacts are an audience of their own, and you can share contact details with them.',
    contactsAction: 'Manage contacts',
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
    reference: 'SB number in the family archive',
    relationship: 'To you: {{relationship}}',
    showAncestors: 'Show ancestors',
    editInWebtrees: 'Open and edit in webtrees',
    editInWebtreesHint: 'You see this link because you may edit the family tree.',
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
    cannot_reply:
      'This message cannot be answered here — the sender’s address does not belong to an account in the family tree.',
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
