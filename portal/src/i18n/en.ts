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
    remember: 'Stay signed in',
    rememberHint_one:
      'This device stays signed in for a day, so you will not have to type your password again in that time. Whoever picks the device up unlocked is then you — so switch this on only on your own phone. "Sign out" in Settings ends it at once.',
    rememberHint_other:
      'This device stays signed in for {{count}} days, so you will not have to type your password again in that time. Whoever picks the device up unlocked is then you — so switch this on only on your own phone. "Sign out" in Settings ends it at once.',
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
    findLegend: 'Find a person',
    findHint: 'Name, nickname or archive number. You may invite anybody you can see.',
    findResults: 'People found',
    findNone: 'Nobody was found for "{{query}}".',
    chosen: 'Chosen: {{name}}',
    whoLegend: 'Choose a person',
    whoPlaceholder: 'Please choose …',
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
      shareTitle: 'An invitation to the Sack family app',
      shareText: 'You are invited to our family app. This link sets up your account:',
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
    keptHint:
      '"Nobody" means the entry is kept — for posting the family magazine, for instance — but shown to nobody in the portal. To delete it for good, empty the field and save.',
    keptNote: 'Kept, but shown to nobody.',
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
    address: {
      street: 'Street and number',
      postcode: 'Postcode',
      city: 'Town',
      country: 'Country',
    },
    summaryIntro: 'This is what you are sharing at the moment. You can change it, or remove it, whenever you like.',
    empty: 'Not given',
    sharedWith: 'Visible to: {{audience}}',
    change: 'Change contact details',
    cancel: 'Cancel',
    save: 'Save contact details',
    saving: 'Saving …',
    saved: 'Your contact details are saved.',
    sharedTitle: 'Contact',
    off: {
      title: 'Contact details are switched off',
      body: 'This family does not share contact details through the portal.',
    },
  },
  conversation: {
    noneTitle: 'No conversations yet',
    noneBody: 'Pick somebody from your contacts and start writing.',
    noneAction: 'Start a conversation',
    listTitle: 'Conversations',
    start: 'New conversation',
    back: 'Back to messages',
    profile: 'Go to profile',
    empty: 'Nothing said yet. Write the first message.',
    write: 'Your message',
    send: 'Send',
    sending: 'Sending …',
    read: 'read',
    unread: '{{count}} unread',
    you: 'You',
    delete: 'Delete for me',
    deleteExplain: 'The message disappears for you only. The other person keeps their copy.',
    deleteConfirm: 'Delete',
    notifyNotice:
      'The other person is told only that a message is waiting in the portal — neither your name nor the text is in the notification.',
  },
  newConversation: {
    title: 'New conversation',
    noneTitle: 'No contacts yet',
    noneBody:
      'A conversation starts with somebody from your contacts. Add a contact first.',
    noneAction: 'Go to my contacts',
    filter: 'Search for a name',
    noMatch: 'No contact with that name.',
    elsewhere: 'Somebody who is not in your contacts?',
    elsewhereAction: 'Search the member directory',
  },
  message: {
    title: 'Message to {{name}}',
    subject: 'Subject',
    body: 'Your message',
    send: 'Send message',
    open: 'Write a message',
    opening: 'Opening …',
    sending: 'Sending …',
    sent: 'Your message is on its way.',
    notifyNotice:
      'The other person is told only that a message is waiting in the portal — neither your name nor the text is in the notification. Your email address does not travel with it.',
  },
  messages: {
    inboxTitle: 'Other messages',
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
    tabs: 'Contacts and new connections',
    tabMine: 'Contacts',
    tabNew: 'Add someone',
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
    sendLink: 'Send a link',
    linkBody:
      'If you can already reach the person — by email, messenger, text — send them a link. Whoever opens it and taps “Connect” is connected with you. The link lasts {{count}} days.',
    linkCreate: 'Make a link',
    linkAnother: 'Make another link',
    linkLabel: 'Your link',
    linkShareTitle: 'Connect in the family portal',
    linkOnce:
      'The link works exactly once and expires after {{count}} days. Send it to that one person only — anybody else who gets hold of it would be connected with you.',
    linkOpen: 'Links you sent that nobody has used',
    linkExpires: 'Valid until {{date}}',
    linkWithdraw: 'Withdraw',
    linkOpenHint:
      'The portal does not know who you sent a link to — you did that yourself. Whoever uses one appears above as a contact.',
    byReference: 'Connect using an SB number',
    referenceBody:
      'The SB number is shown in the portal under the person’s name, for example “10/1335.21”. They receive a request and decide for themselves — even if they are not visible in the member directory.',
    kinship: 'To you: {{relationship}}',
    kinshipHint:
      'Worked out from the two archive numbers. It says nothing about whether that number belongs to anybody.',
    referenceGroup: 'SB number',
    branchLabel: 'Branch',
    branchPlaceholder: '—',
    referenceLabel: 'Number',
    referenceHint:
      'The part after the slash, exactly as it is written – letters and a marker on the end included, such as “!” for the spouse. A full stop or a comma makes no difference.',
    referencePlaceholder: '1335.21',
    ask: 'Send request',
    askThis: 'Connect',
    asking: 'One moment …',
    requestedQuietly:
      'If that number belongs to a member, your request is on its way. You will hear nothing until it is accepted — and then they appear here as a contact.',
    requested: 'Your request has reached {{name}}. Once they confirm it, they appear here.',
    alreadyConnected: 'That number belongs to {{name}}, who is already one of your contacts. Nothing was sent.',
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
      body: 'This family does not make new connections through the portal. You can still see the ones you have.',
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
  tree: {
    title: 'Family tree',
    intro:
      'Search the family archive by name or archive number — or read down the surnames and the places.',
    tabSearch: 'Search',
    tabCalculator: 'Calculator',
    calc: {
      intro:
        'An archive number is not a label but a path: the line, then one character per generation. So the relationship between two people can be worked out from their two numbers alone — no records, no limit, and even for people who are not in the tree at all.',
      first: 'Archive number 1',
      firstHint: 'Your own number is filled in. You can overwrite it.',
      second: 'Archive number 2',
      result: '{{second}} to {{first}}',
      note: 'Nothing is looked up and nobody is named — only the two numbers are used.',
      problem: {
        invalid_a: 'Archive number 1 is not a valid number.',
        invalid_b: 'Archive number 2 is not a valid number.',
        identical: 'Both numbers name the same person.',
        incomplete: '',
      },
    },
    tabSurnames: 'Names',
    tabPlaces: 'Places',
    search: 'Name or archive number',
    searchHint:
      'The dead are fully searchable. Living people appear only where they have listed themselves in the member directory.',
    open: 'Search the family tree',
    count_one: '{{count}} person',
    count_other: '{{count}} people',
    noResults: {
      title: 'No matches',
      body: 'Nobody was found for "{{query}}". Try a shorter search term.',
      action: 'Clear the search',
    },
    tooMany:
      'There are more matches than can be shown here. A more precise search term narrows the list.',
    truncated: 'The tree is larger than this overview. The counts are a lower bound.',
    backToSurnames: 'Back to the names',
    backToPlaces: 'Back to the places',
    showingSurname: 'Everybody named {{name}}',
    showingPlace: 'Everybody with an event in {{name}}',
    surnames: {
      empty: {
        title: 'No names yet',
        body: 'No surname in the tree is visible to you yet.',
      },
    },
    places: {
      empty: {
        title: 'No places yet',
        body: 'No place in the tree is visible to you yet.',
      },
    },
  },
  refresh: {
    pull: 'Pull down to refresh',
    release: 'Release to refresh',
    running: 'Refreshing …',
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
  },
  member: {
    back: 'Back to the list',
    private: {
      title: 'No details visible',
      body: 'None of this member’s family tree data is shared with you.',
    },
  },
  install: {
    title: 'On the home screen',
    body:
      'You can put the Sack family app on your home screen. It then opens with one tap, without an address bar and without hunting for it in the browser.',
    action: 'Add to home screen',
    apple: 'Tap the share icon at the bottom, then "Add to Home Screen".',
    appleOther:
      'Tap the share icon at the top, then "Add to Home Screen". In Safari that icon is at the bottom.',
    android:
      'Tap the three dots at the top right, then "Install app" — depending on the version it may say "Add to Home screen".',
    webview:
      'This page was opened inside another app, which cannot do this. Tap the three dots and then "Open in browser", where it works.',
    done: 'The app is already on this device’s home screen.',
    later: 'Later',
    understood: 'Got it',
    staysInSettings: 'You can find this again at any time under “Settings”.',
  },
  notifications: {
    title: 'Notifications',
    body:
      'You can be told when a new message arrives, even while the app is closed.',
    privacy:
      'The lock screen says only that a message is there. Neither the sender’s name nor the text is shown.',
    switchOn: 'Turn notifications on',
    switchOff: 'Turn off on this device',
    working: 'One moment …',
    on: 'This device is being notified.',
    untilSignOut:
      'Signing out unsubscribes this device. Signing back in here switches it on again by itself — until you tap “Switch off on this device”.',
    needsInstall:
      'On an iPhone or iPad, notifications work only once the app is on the home screen. How to put it there is at the top of this page, under "On the home screen". You can turn notifications on here afterwards.',
    blocked:
      'Your browser is blocking notifications for this site. Only the browser’s own settings can allow them again.',
  },
  claim: {
    title: 'Join the family portal',
    intro:
      'You were sent this link in one of the family’s round-robin letters. Enter the email address that letter arrived at, and your personal invitation will be sent there.',
    email: 'Your email address',
    emailHint:
      'The address the letter arrived at. We cannot send an invitation anywhere else.',
    submit: 'Ask for an invitation',
    sending: 'Sending…',
    missing: 'Please enter your email address.',
    sent: {
      title: 'Please check your inbox',
      body:
        'If this address belongs to the family, your personal invitation is on its way. The link in it is yours alone and works once — please do not pass it on. Have a look in the spam folder too.',
    },
    haveAccount: 'Already have an account?',
    backToLogin: 'Go to sign in',
  },
  lists: {
    title: 'The family’s round-robin letters',
    intro:
      'These go to {{address}}, the address your account uses. You can leave any of them at any time, and join again just as easily.',
    pending: 'Your answer has been taken down and is being passed on.',
    failed:
      'Your answer has been taken down, but could not be passed on yet. Somebody is looking at it — there is nothing else for you to do.',
    noAddress:
      'Your account has no email address, so there is nowhere for the family’s post to go. Please ask whoever looks after the family tree.',
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
    languageAccountHint:
      'Applies to your account — on every other device too, and to email from the family tree.',
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
    branch: 'Branch of the family',
    relationship: 'To you: {{relationship}}',
    showAncestors: 'Show ancestors',
    editInWebtrees: 'Open and edit in webtrees',
    editInWebtreesHint: 'You see this link because you may edit the family tree.',
    photos: 'Photographs',
    photoUntitled: 'Untitled photograph',
    photoClose: 'Close',
    noEvents: 'No further details are recorded for this person.',
    notVisible: 'No family-tree details visible',
    sex: {
      M: 'male',
      F: 'female',
      X: 'other',
      U: 'unknown',
    },
  },
  link: {
    copy: 'Copy',
    share: 'Share',
    copied: 'The link is copied. Paste it into your message.',
  },
  myPhotos: {
    title: 'My photographs',
    body: 'You can add a photograph of yourself. It appears on your record and is shown to every member who may see you.',
    rule:
      'A photograph of a living person is shown in the portal only where that person uploaded it themselves — even if the family tree holds others. Photographs of people who have died are unchanged.',
    choose: 'Choose a photograph',
    hint: 'JPEG, PNG or WebP, at most 4 MB. Where it was taken, and anything else hidden in the file, is removed on upload.',
    remove: 'Remove',
    untitled: 'Photograph',
    waiting:
      'The photograph is saved. Because a change to your record is still waiting for approval, it appears once that has been approved.',
  },
  person: {
    title: 'Person',
    backToProfile: 'Back to my profile',
    invite: {
      title: 'Not in the portal yet',
      body: '{{name}} does not have access yet. You can create an invitation and pass the link on yourself.',
      action: 'Invite',
    },
  },
  ancestors: {
    title: 'Ancestors',
    generation: {
      1: 'Parents',
      2: 'Grandparents',
      3: 'Great-grandparents',
      4: 'Great-great-grandparents',
      nth: 'Generation {{n}}',
    },
    path: {
      your: {
        father: 'Your father',
        mother: 'Your mother',
      },
      possessive: {
        father: "Father's",
        mother: "Mother's",
      },
      father: 'father',
      mother: 'mother',
    },
    none: {
      title: 'No ancestors recorded',
      body: 'The family tree records no parents for this person.',
    },
    private: {
      name: 'Not shown',
      member: 'Listed in the member directory',
    },
    truncated:
      'The archive goes back further than can be shown here. Open one of the people at the top to carry on from there.',
    privacyNote:
      'Where somebody is not shared with you — which is nearly always the living — the row says only that somebody is there: no name, no dates. Anyone who has listed themselves in the member directory is named with the name they publish there; nothing from the family tree is shown for them either.',
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
    newWindow: 'opens in a new window',
  },
}
