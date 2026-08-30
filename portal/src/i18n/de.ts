export const de = {
  app: {
    name: 'Sack Familienapp',
    skipToContent: 'Zum Inhalt springen',
    offline: 'Keine Internetverbindung. Es können gerade keine neuen Inhalte geladen werden.',
  },
  nav: {
    profile: 'Mein Profil',
    contacts: 'Kontakte',
    messages: 'Nachrichten',
    badge: {
      unread: '{{count}} ungelesen',
      connections_one: '{{count}} Verbindungsanfrage',
      connections_other: '{{count}} Verbindungsanfragen',
    },
    settings: 'Einstellungen',
    main: 'Hauptnavigation',
  },
  login: {
    title: 'Anmelden',
    intro: 'Melde dich mit deinem Benutzernamen und Passwort an.',
    username: 'Benutzername oder E-Mail-Adresse',
    password: 'Passwort',
    submit: 'Anmelden',
    submitting: 'Einen Moment …',
    remember: 'Angemeldet bleiben',
    rememberHint_one:
      'Du bleibst auf diesem Gerät einen Tag angemeldet und musst dein Passwort so lange nicht wieder eingeben. Wer das Gerät entsperrt in die Hand bekommt, ist dann du — schalte das also nur auf deinem eigenen Telefon ein. „Abmelden“ in den Einstellungen beendet es sofort.',
    rememberHint_other:
      'Du bleibst auf diesem Gerät {{count}} Tage angemeldet und musst dein Passwort so lange nicht wieder eingeben. Wer das Gerät entsperrt in die Hand bekommt, ist dann du — schalte das also nur auf deinem eigenen Telefon ein. „Abmelden“ in den Einstellungen beendet es sofort.',
    forgotten: 'Passwort vergessen?',
    noAccount: 'Noch keinen Zugang? Hier beantragen',
    failed: 'Benutzername oder Passwort ist falsch. Bitte versuche es noch einmal.',
    missing: 'Bitte fülle beide Felder aus.',
  },
  password: {
    requestTitle: 'Passwort zurücksetzen',
    requestIntro:
      'Gib deine E-Mail-Adresse ein. Wenn dazu ein Konto gehört, schicken wir dir einen Link zum Zurücksetzen.',
    email: 'E-Mail-Adresse',
    send: 'Link anfordern',
    sending: 'Wird gesendet …',
    sent: {
      title: 'Bitte sieh in dein Postfach',
      body:
        'Falls zu dieser Adresse ein Konto gehört, ist der Link unterwegs. Er gilt eine Stunde. Sieh auch im Spam-Ordner nach.',
    },
    backToLogin: 'Zurück zur Anmeldung',
    resetTitle: 'Neues Passwort festlegen',
    resetIntro: 'Wähle ein Passwort mit mindestens acht Zeichen.',
    newPassword: 'Neues Passwort',
    repeatPassword: 'Passwort wiederholen',
    save: 'Passwort speichern',
    saving: 'Wird gespeichert …',
    mismatch: 'Die beiden Passwörter stimmen nicht überein.',
    tooShort: 'Das Passwort muss mindestens acht Zeichen lang sein.',
    missingToken: {
      title: 'Dieser Link ist unvollständig',
      body: 'Bitte öffne den Link aus der E-Mail noch einmal oder fordere einen neuen an.',
      action: 'Neuen Link anfordern',
    },
    expired: 'Dieser Link ist abgelaufen oder wurde schon benutzt. Bitte fordere einen neuen an.',
  },
  invitation: {
    title: 'Willkommen in der Familie',
    intro:
      'Du wurdest zum Mitgliederportal von „{{tree}}“ eingeladen. Lege hier deinen Zugang an — das dauert eine Minute.',
    invitedAs: 'Die Einladung gilt für:',
    realName: 'Dein Name',
    username: 'Benutzername',
    usernameHint:
      'Damit meldest du dich künftig an. Mindestens drei Zeichen, ohne Leerzeichen und ohne „@“.',
    email: 'E-Mail-Adresse',
    emailHint:
      'Hierhin geht der Link, falls du einmal dein Passwort vergisst. Das muss nicht die Adresse sein, an die die Einladung ging.',
    passwordHint: 'Mindestens acht Zeichen.',
    save: 'Zugang anlegen',
    saving: 'Wird angelegt …',
    usernameTaken:
      'Diesen Benutzernamen gibt es schon. Bitte wähle einen anderen — deine Einladung gilt weiterhin.',
    emailTaken:
      'Zu dieser E-Mail-Adresse gibt es schon ein Konto. Bitte nimm eine andere Adresse oder melde dich mit dem vorhandenen Konto an.',
    badDetails: 'Bitte prüfe deine Angaben. Etwas davon konnte der Server nicht annehmen.',
    privacyNote:
      'Im Portal siehst du nur, was für dich freigegeben ist. Angaben zu lebenden Personen bleiben geschützt.',
    unusable: {
      title: 'Diese Einladung gilt nicht mehr',
      body:
        'Der Link ist abgelaufen oder wurde schon benutzt. Bitte frag in der Familienverwaltung nach einer neuen Einladung.',
      action: 'Zur Anmeldung',
    },
  },
  invite: {
    title: 'Jemanden einladen',
    intro:
      'Du kannst deiner engen Familie einen Zugang zum Portal einrichten. Du wählst die Person aus, bekommst einen Link und schickst ihn selbst — so, wie du diese Person sonst auch erreichst.',
    chooseTitle: 'Wen möchtest du einladen?',
    findLegend: 'Person suchen',
    findHint:
      'Name, Spitzname oder SB-Nr. Du kannst jede Person einladen, die du sehen kannst.',
    findResults: 'Gefundene Personen',
    findNone: 'Für „{{query}}" wurde niemand gefunden.',
    chosen: 'Ausgewählt: {{name}}',
    whoLegend: 'Person auswählen',
    whoPlaceholder: 'Bitte auswählen …',
    email: 'E-Mail-Adresse (freiwillig)',
    emailHint:
      'Wird nur für sie ins Formular vorausgefüllt. Der Link geht nicht automatisch raus — den verschickst du selbst.',
    create: 'Einladung erstellen',
    creating: 'Wird erstellt …',
    remaining_one: 'Du kannst noch {{count}} Einladung offen haben.',
    remaining_other: 'Du kannst noch {{count}} Einladungen offen haben.',
    pickSomebody: 'Bitte wähle zuerst eine Person aus.',
    refused:
      'Diese Person kannst du nicht einladen. Vielleicht hat sie schon einen Zugang oder ist bereits eingeladen.',
    ready: {
      shareTitle: 'Einladung zur Sack Familienapp',
      shareText: 'Du bist in unsere Familien-App eingeladen. Über diesen Link kannst du dein Konto einrichten:',
      title: 'Die Einladung ist fertig',
      body:
        'Kopiere den Link und schicke ihn dieser Person — per Nachricht, E-Mail oder wie du magst. Der Link wird nur dieses eine Mal angezeigt und lässt sich später nicht wieder aufrufen. Falls er verloren geht: Einladung zurücknehmen und eine neue erstellen.',
      label: 'Einladungslink',
      done: 'Habe ich kopiert',
    },
    outstandingTitle: 'Deine offenen Einladungen',
    expires: 'Gültig bis {{date}}',
    withdraw: 'Zurücknehmen',
    none: {
      title: 'Zurzeit niemand zum Einladen',
      body:
        'Alle deine nahen Angehörigen sind schon dabei, bereits eingeladen, oder im Stammbaum als verstorben eingetragen.',
    },
    quota: {
      title: 'Du hast schon genug offene Einladungen',
      body:
        'Nimm eine offene Einladung zurück oder warte, bis sie benutzt wurde — dann geht es weiter.',
    },
    off: {
      title: 'Einladungen durch Mitglieder sind ausgeschaltet',
      body: 'In dieser Familie legt die Familienverwaltung die Zugänge an. Frag dort nach.',
    },
    noRecord: {
      title: 'Dein Konto ist noch nicht verknüpft',
      body:
        'Solange dein Konto mit keiner Person im Stammbaum verbunden ist, weiß das Portal nicht, wer deine Familie ist. Bitte wende dich an die Familienverwaltung.',
    },
  },
  contact: {
    intro:
      'Trage ein, was dich erreichbar macht — und entscheide für jeden Eintrag einzeln, wer ihn sehen darf. Was du leer lässt, wird nicht geteilt.',
    keptHint:
      '„Niemand“ heißt: Der Eintrag bleibt gespeichert — etwa für den Versand der Familienzeitschrift — wird aber niemandem im Portal gezeigt. Zum endgültigen Löschen leere das Feld und speichere.',
    keptNote: 'Gespeichert, aber niemandem gezeigt.',
    kind: {
      email: 'E-Mail-Adresse',
      phone: 'Telefonnummer',
      address: 'Anschrift',
    },
    hint: {
      email: 'Kann eine andere sein als die, mit der du dich anmeldest.',
      phone: 'So, wie du sie jemandem am Telefon nennen würdest.',
      address: 'Straße, Postleitzahl und Ort.',
    },
    audienceLegend: 'Wer darf das sehen?',
    audience: {
      nobody: 'Niemand',
      close_family: 'Nur meine enge Familie',
      connections: 'Nur meine Kontakte',
      members: 'Alle Mitglieder im Portal',
    },
    address: {
      street: 'Straße und Hausnummer',
      postcode: 'Postleitzahl',
      city: 'Ort',
      country: 'Land',
    },
    summaryIntro: 'Das teilst du zurzeit. Du kannst es jederzeit ändern oder wieder löschen.',
    empty: 'Nicht angegeben',
    sharedWith: 'Sichtbar für: {{audience}}',
    change: 'Kontaktdaten ändern',
    cancel: 'Abbrechen',
    save: 'Kontaktdaten speichern',
    saving: 'Wird gespeichert …',
    saved: 'Deine Kontaktdaten sind gespeichert.',
    sharedTitle: 'Kontakt',
    off: {
      title: 'Kontaktdaten sind ausgeschaltet',
      body: 'In dieser Familie werden über das Portal keine Kontaktdaten geteilt.',
    },
  },
  conversation: {
    noneTitle: 'Noch keine Gespräche',
    noneBody: 'Wähle jemanden aus deinen Kontakten aus, und schreib los.',
    noneAction: 'Gespräch beginnen',
    listTitle: 'Gespräche',
    start: 'Neues Gespräch',
    back: 'Zurück zu den Nachrichten',
    profile: 'Zum Profil',
    empty: 'Noch nichts gesagt. Schreib die erste Nachricht.',
    write: 'Deine Nachricht',
    send: 'Senden',
    sending: 'Wird gesendet …',
    read: 'gelesen',
    unread: '{{count}} ungelesen',
    you: 'Du',
    delete: 'Für mich löschen',
    deleteExplain:
      'Die Nachricht verschwindet nur bei dir. Die andere Person behält ihre Kopie.',
    deleteConfirm: 'Löschen',
    notifyNotice:
      'Die andere Person erfährt nur, dass eine Nachricht im Portal wartet — weder dein Name noch der Text stehen in der Benachrichtigung.',
  },
  newConversation: {
    title: 'Neues Gespräch',
    noneTitle: 'Noch keine Kontakte',
    noneBody:
      'Ein Gespräch beginnt mit jemandem aus deinen Kontakten. Lege zuerst einen Kontakt an.',
    noneAction: 'Zu meinen Kontakten',
    filter: 'Name suchen',
    noMatch: 'Kein Kontakt mit diesem Namen.',
    elsewhere: 'Jemand, der nicht in deinen Kontakten steht?',
    elsewhereAction: 'Im Mitgliederverzeichnis suchen',
  },
  message: {
    title: 'Nachricht an {{name}}',
    subject: 'Betreff',
    body: 'Deine Nachricht',
    send: 'Nachricht senden',
    open: 'Nachricht schreiben',
    opening: 'Wird geöffnet …',
    sending: 'Wird gesendet …',
    sent: 'Deine Nachricht ist unterwegs.',
    notifyNotice:
      'Die andere Person erfährt nur, dass eine Nachricht im Portal wartet — weder dein Name noch der Text stehen in der Benachrichtigung. Deine E-Mail-Adresse wird nicht mitgeschickt.',
  },
  messages: {
    inboxTitle: 'Sonstige Nachrichten',
    title: 'Nachrichten',
    unread: 'ungelesen',
    markUnread: 'Als ungelesen markieren',
    reply: 'Antworten',
    replyAddressNotice:
      'Damit man dir antworten kann, wird deine E-Mail-Adresse als Absenderadresse mitgeschickt — auch dann, wenn du sie in deinen Kontaktdaten nicht freigegeben hast.',
    replyLabel: 'Deine Antwort',
    replyCancel: 'Abbrechen',
    replySend: 'Antwort senden',
    replySending: 'Wird gesendet …',
    replySent: 'Deine Antwort ist unterwegs. Eine Kopie wird hier nicht aufbewahrt.',
    replyImpossible:
      'Auf diese Nachricht kann hier nicht geantwortet werden — die Absenderadresse gehört zu keinem Konto im Stammbaum.',
    delete: 'Löschen',
    none: {
      title: 'Keine Nachrichten',
      body: 'Hier erscheinen Nachrichten, die dir andere Mitglieder oder die Familienverwaltung schicken.',
    },
    note:
      'Das ist dein Postfach in webtrees — dieselben Nachrichten, die du auch dort siehst. Was du hier löschst, ist auch dort gelöscht.',
  },
  profile: {
    title: 'Mein Profil',
    noRecord: {
      title: 'Dein Eintrag im Stammbaum fehlt noch',
      body: 'Dein Konto ist noch mit keiner Person im Stammbaum verknüpft. Die Familienverwaltung kann das einrichten.',
    },
    edit: 'Meine Daten ändern',
    openInWebtrees: 'Stammbaum und Diagramme öffnen',
    pending: {
      title: 'Deine Änderung wird geprüft',
      body:
        'Deine Angaben wurden weitergegeben. Bis jemand aus der Familienverwaltung sie freigibt, siehst du hier weiterhin den bisherigen Stand.',
    },
  },
  edit: {
    title: 'Meine Daten ändern',
    intro:
      'Deine Änderungen werden nicht sofort übernommen. Die Familienverwaltung sieht sie sich an und gibt sie frei.',
    section: {
      name: 'Name',
      birth: 'Geburt',
      work: 'Beruf',
      contact: 'Kontakt',
    },
    givenNames: 'Vorname',
    surname: 'Nachname',
    birthDate: 'Geburtsdatum',
    birthDateStored: 'Bisher gespeichert: {{date}}. Dieses Datum bleibt unverändert, solange du hier keines auswählst.',
    birthPlace: 'Geburtsort',
    occupation: 'Beruf',
    address: 'Anschrift',
    email: 'E-Mail-Adresse',
    phone: 'Telefon',
    website: 'Webseite',
    contactHint: 'Deine Kontaktdaten sehen nur du selbst und die Familienverwaltung.',
    submit: 'Änderung einreichen',
    submitting: 'Wird eingereicht …',
    cancel: 'Abbrechen',
    unchanged: 'Du hast nichts geändert.',
    applied: {
      body: 'Deine Änderung wurde übernommen und ist im Stammbaum sichtbar.',
    },
    submitted: {
      title: 'Danke — wir haben deine Änderung erhalten',
      body: 'Sie wird geprüft und danach übernommen. Du musst nichts weiter tun.',
      action: 'Zurück zum Profil',
    },
    blocked: {
      title: 'Eine Änderung wartet noch',
      body:
        'Du hast bereits eine Änderung eingereicht, die noch nicht freigegeben ist. Bitte warte, bis sie geprüft wurde.',
    },
    locked: 'Dieser Eintrag ist gesperrt und kann nicht geändert werden. Bitte wende dich an die Familienverwaltung.',
    noRecord: 'Dein Konto ist noch mit keiner Person im Stammbaum verknüpft.',
  },
  contacts: {
    title: 'Meine Kontakte',
    tabs: 'Kontakte und neue Verbindungen',
    tabMine: 'Kontakte',
    tabNew: 'Neu verbinden',
    intro:
      'Hier stehen die Menschen, mit denen du dich verbunden hast. Eine Verbindung entsteht immer zu zweit — und du kannst sie jederzeit wieder lösen.',
    find: 'Jemanden im Portal suchen',
    findBody:
      'Alle Mitglieder, die einer Anzeige zugestimmt haben. Such nach einem Namen, oder lass das Feld leer, um alle zu sehen.',
    findLabel: 'Name',
    findAction: 'Im Verzeichnis suchen',
    showCode: 'Sich vor Ort verbinden',
    codeBody:
      'Zeig diesen Code auf deinem Bildschirm. Die andere Person hält einfach ihre Handykamera darauf — dann seid ihr beide verbunden. Der Code gilt {{count}} Minuten.',
    codeShow: 'Code anzeigen',
    codeRenew: 'Neuen Code erzeugen',
    codeHide: 'Code ungültig machen',
    codeHidden: 'Der Code ist ungültig. Du kannst jederzeit einen neuen erzeugen.',
    codeValid: 'Gilt noch etwa {{count}} Minuten. Danach brauchst du einen neuen Code.',
    codeAlt: 'QR-Code, mit dem sich jemand mit dir verbinden kann',
    sendLink: 'Einen Link verschicken',
    linkBody:
      'Wenn du die Person schon erreichen kannst — per E-Mail, Messenger, SMS —, schick ihr einen Link. Wer ihn öffnet und auf „Verbinden" tippt, ist mit dir verbunden. Der Link gilt {{count}} Tage.',
    linkCreate: 'Link erzeugen',
    linkAnother: 'Weiteren Link erzeugen',
    linkLabel: 'Dein Link',
    linkShareTitle: 'Im Familienportal verbinden',
    linkOnce:
      'Der Link funktioniert genau einmal und läuft nach {{count}} Tagen ab. Schick ihn nur der einen Person — wer ihn sonst in die Hände bekommt, wäre mit dir verbunden.',
    linkOpen: 'Verschickte Links, die noch niemand benutzt hat',
    linkExpires: 'Gültig bis {{date}}',
    linkWithdraw: 'Zurückziehen',
    linkOpenHint:
      'An wen du einen Link geschickt hast, weiß das Portal nicht — das hast du selbst getan. Wer ihn benutzt, erscheint oben als Kontakt.',
    byReference: 'Mit der SB-Nummer verbinden',
    referenceBody:
      'Die SB-Nummer steht im Portal unter dem Namen der Person, zum Beispiel „10/1335.21“. Die andere Person bekommt eine Anfrage und entscheidet selbst — auch dann, wenn sie im Mitgliederverzeichnis nicht sichtbar ist.',
    kinship: 'Für dich: {{relationship}}',
    kinshipHint:
      'Aus den beiden SB-Nummern gerechnet. Es sagt nichts darüber, ob diese Nummer vergeben ist.',
    referenceGroup: 'SB-Nummer',
    branchLabel: 'Zweig',
    branchPlaceholder: '—',
    referenceLabel: 'Nummer',
    referenceHint:
      'Der Teil nach dem Schrägstrich, genau wie er dasteht – auch mit Buchstaben und einem Zeichen am Ende, etwa „!“ für den Ehepartner. Punkt oder Komma ist einerlei.',
    referencePlaceholder: '1335.21',
    ask: 'Anfrage senden',
    askThis: 'Verbinden',
    asking: 'Einen Moment …',
    requestedQuietly:
      'Wenn diese Nummer zu einem Mitglied gehört, ist deine Anfrage unterwegs. Du erfährst erst davon, wenn die Anfrage angenommen wird — dann steht der Kontakt hier.',
    requested: 'Deine Anfrage ist bei {{name}} — sobald sie bestätigt wird, erscheint der Kontakt hier.',
    alreadyConnected: 'Diese Nummer gehört zu {{name}} — du bist bereits verbunden. Es wurde nichts geschickt.',
    connected: 'Du bist jetzt mit {{name}} verbunden.',
    incoming: 'Anfragen an dich',
    outgoing: 'Deine Anfragen',
    waiting: 'Wartet auf eine Antwort.',
    asksYou: 'möchte sich mit dir verbinden.',
    asksYouAs: 'möchte sich mit dir verbinden — im Stammbaum: {{name}}.',
    accept: 'Annehmen',
    decline: 'Ablehnen',
    withdraw: 'Zurückziehen',
    disconnect: 'Verbindung lösen',
    sure: 'Verbindung mit {{name}} wirklich beenden?',
    sureYes: 'Ja, beenden',
    sureNo: 'Abbrechen',
    list: 'Verbunden',
    withMember: 'Verbindung',
    state: {
      none: 'Du bist mit dieser Person noch nicht verbunden.',
      requested: 'Deine Anfrage ist unterwegs und wartet auf eine Antwort.',
      incoming: '{{name}} möchte sich mit dir verbinden.',
      connected: 'Du bist verbunden.',
    },
    none: {
      title: 'Noch keine Kontakte',
      body: 'Verbinde dich beim nächsten Familientreffen mit dem Code oben — oder über die SB-Nummer, wenn dir jemand seine genannt hat.',
    },
    off: {
      title: 'Verbindungen sind ausgeschaltet',
      body: 'In dieser Familie werden über das Portal keine neuen Verbindungen geknüpft. Bestehende Kontakte kannst du weiterhin sehen.',
    },
  },
  connect: {
    title: 'Verbinden',
    intro:
      'Du hast einen Verbindungscode geöffnet. Wenn du fortfährst, bist du mit der Person, die den Code gezeigt hat, verbunden.',
    connect: 'Jetzt verbinden',
    connecting: 'Einen Moment …',
    done: 'Du bist jetzt mit {{name}} verbunden.',
    toContacts: 'Zu meinen Kontakten',
    missing: {
      title: 'Dieser Link ist unvollständig',
      body: 'Bitte scanne den Code noch einmal. Falls das nicht klappt, lass dir einen neuen Code zeigen.',
    },
  },
  tree: {
    title: 'Stammbaum',
    intro:
      'Such im Familienarchiv nach einem Namen oder einer SB-Nr. — oder blättere durch die Namen und die Orte.',
    tabSearch: 'Suche',
    tabCalculator: 'Rechner',
    calc: {
      intro:
        'Die SB-Nr. ist keine Bezeichnung, sondern ein Weg: Linie, dann ein Zeichen je Generation. Deshalb lässt sich aus zwei Nummern allein der Verwandtschaftsgrad berechnen — ohne Daten, ohne Grenze, auch für Personen, die gar nicht im Stammbaum stehen.',
      first: 'SB-Nr. 1',
      firstHint: 'Deine eigene Nummer ist schon eingetragen. Du kannst sie überschreiben.',
      second: 'SB-Nr. 2',
      also: 'Über eine zweite Linie außerdem: {{others}}',
      result: '{{second}} zu {{first}}',
      note: 'Es wird nichts nachgeschlagen und niemand genannt — gerechnet wird nur mit den beiden Nummern.',
      problem: {
        invalid_a: 'SB-Nr. 1 ist keine gültige Nummer.',
        invalid_b: 'SB-Nr. 2 ist keine gültige Nummer.',
        identical: 'Beide Nummern bezeichnen dieselbe Person.',
        incomplete: '',
      },
    },
    tabSurnames: 'Namen',
    tabPlaces: 'Orte',
    search: 'Name oder SB-Nr.',
    searchHint:
      'Verstorbene sind vollständig durchsuchbar. Lebende erscheinen nur, wenn sie sich selbst im Mitgliederverzeichnis freigegeben haben.',
    open: 'Stammbaum durchsuchen',
    count_one: '{{count}} Person',
    count_other: '{{count}} Personen',
    noResults: {
      title: 'Keine Treffer',
      body: 'Für „{{query}}" wurde niemand gefunden. Versuch einen kürzeren Suchbegriff.',
      action: 'Suche zurücksetzen',
    },
    tooMany:
      'Es gibt mehr Treffer, als hier gezeigt werden können. Ein genauerer Suchbegriff grenzt die Liste ein.',
    truncated: 'Der Stammbaum ist größer als diese Übersicht. Die Zahlen sind Mindestangaben.',
    backToSurnames: 'Zurück zu den Namen',
    backToPlaces: 'Zurück zu den Orten',
    showingSurname: 'Alle mit dem Namen {{name}}',
    showingPlace: 'Alle mit einem Ereignis in {{name}}',
    surnames: {
      empty: {
        title: 'Noch keine Namen',
        body: 'Im Stammbaum ist für dich noch kein Name sichtbar.',
      },
    },
    places: {
      empty: {
        title: 'Noch keine Orte',
        body: 'Im Stammbaum ist für dich noch kein Ort sichtbar.',
      },
    },
  },
  refresh: {
    pull: 'Zum Aktualisieren nach unten ziehen',
    release: 'Loslassen zum Aktualisieren',
    running: 'Wird aktualisiert …',
  },
  members: {
    back: 'Zurück zu Kontakte',
    title: 'Mitglieder',
    search: 'Nach Namen suchen',
    searchHint: 'Es werden nur Mitglieder angezeigt, die einer Anzeige zugestimmt haben.',
    count_one: '{{count}} Mitglied',
    count_other: '{{count}} Mitglieder',
    empty: {
      title: 'Noch keine Mitglieder sichtbar',
      body: 'Sobald Mitglieder der Anzeige im Verzeichnis zustimmen, erscheinen sie hier.',
    },
    noResults: {
      title: 'Keine Treffer',
      body: 'Für „{{query}}" wurde niemand gefunden. Versuch einen kürzeren Suchbegriff.',
      action: 'Suche zurücksetzen',
    },
    previous: 'Zurück',
    next: 'Weiter',
    page: 'Seite {{page}} von {{pages}}',
    connectWith: 'Verbinden mit {{name}}',
    acceptFrom: 'Anfrage von {{name}} annehmen',
    state: {
      requested: 'Angefragt',
      connected: 'Verbunden',
    },
  },
  member: {
    back: 'Zurück zur Übersicht',
    private: {
      title: 'Keine Daten sichtbar',
      body: 'Zu diesem Mitglied sind für dich keine Daten aus dem Stammbaum freigegeben.',
    },
  },
  install: {
    title: 'Auf den Startbildschirm',
    body:
      'Du kannst die Sack Familienapp auf den Startbildschirm legen. Sie öffnet sich dann mit einem Tippen, ohne Adresszeile und ohne Suchen im Browser.',
    action: 'Auf den Startbildschirm legen',
    apple: 'Tippe unten auf das Teilen-Symbol und dann auf „Zum Home-Bildschirm“.',
    appleOther:
      'Tippe oben auf das Teilen-Symbol und dann auf „Zum Home-Bildschirm“. In Safari sitzt dieses Symbol unten.',
    android:
      'Tippe oben rechts auf die drei Punkte und dann auf „App installieren“ — je nach Version heißt es auch „Zum Startbildschirm hinzufügen“.',
    webview:
      'Diese Seite wurde gerade in einer anderen App geöffnet, die das nicht kann. Tippe auf die drei Punkte und dann auf „Im Browser öffnen“ — dort geht es.',
    done: 'Die App liegt bereits auf dem Startbildschirm dieses Geräts.',
    later: 'Später',
    understood: 'Alles klar',
    staysInSettings: 'Du findest das jederzeit wieder unter „Einstellungen“.',
  },
  notifications: {
    title: 'Benachrichtigungen',
    body:
      'Du kannst dich benachrichtigen lassen, wenn eine neue Nachricht ankommt — auch wenn die App gerade nicht offen ist.',
    privacy:
      'Auf dem Sperrbildschirm steht nur, dass eine Nachricht da ist. Weder der Name der Person noch der Text werden angezeigt.',
    switchOn: 'Benachrichtigungen einschalten',
    switchOff: 'Auf diesem Gerät ausschalten',
    working: 'Einen Moment …',
    on: 'Dieses Gerät wird benachrichtigt.',
    untilSignOut:
      'Beim Abmelden wird dieses Gerät abgemeldet. Sobald du dich hier wieder anmeldest, schaltet es sich von selbst wieder ein — bis du oben auf „Auf diesem Gerät ausschalten“ tippst.',
    needsInstall:
      'Auf dem iPhone und iPad gibt es Benachrichtigungen nur, wenn die App auf dem Home-Bildschirm liegt. Wie du sie dorthin legst, steht oben auf dieser Seite unter „Auf den Startbildschirm“. Danach kannst du die Benachrichtigungen hier einschalten.',
    blocked:
      'Dein Browser blockiert Benachrichtigungen für diese Seite. Das lässt sich nur in den Browser-Einstellungen wieder erlauben.',
  },
  claim: {
    title: 'Zum Familienportal anmelden',
    intro:
      'Du hast diesen Link aus einem Rundschreiben der Familie — einer Rundmail oder der Familienzeitschrift. Gib hier die E-Mail-Adresse ein, unter der die Familie dich erreicht; deine persönliche Einladung schicken wir dann genau dorthin.',
    email: 'Deine E-Mail-Adresse',
    emailHint:
      'Die Adresse, unter der du Post von der Familie bekommst. An eine andere können wir nichts schicken.',
    submit: 'Einladung anfordern',
    sending: 'Wird gesendet …',
    missing: 'Bitte gib deine E-Mail-Adresse ein.',
    sent: {
      title: 'Bitte sieh in dein Postfach',
      body:
        'Wenn diese Adresse zur Familie gehört, ist deine persönliche Einladung unterwegs. Der Link darin gilt nur für dich und nur einmal — bitte gib ihn nicht weiter. Sieh auch im Spam-Ordner nach.',
    },
    noMail: 'Du bekommst keine Post von der Familie an eine E-Mail-Adresse?',
    askInstead: 'Zugang beantragen',
    haveAccount: 'Du hast schon einen Zugang?',
    backToLogin: 'Zur Anmeldung',
  },
  request: {
    title: 'Zugang zum Familienportal beantragen',
    intro:
      'Sag uns kurz, wer du bist. Wir legen nichts an und verschicken nichts — deine Angaben landen bei der Familienverwaltung, und ein Mensch entscheidet und schreibt dir.',
    name: 'Dein Name',
    nameHint: 'So, wie die Familie dich kennt.',
    email: 'Deine E-Mail-Adresse',
    emailHint: 'Dorthin ginge deine Einladung, wenn eine ausgestellt wird.',
    reference: 'Deine SB-Nummer (wenn du sie kennst)',
    referenceHint:
      'Die Nummer, die in der Familienzeitschrift neben deinem Namen steht, zum Beispiel 22/1a32.124. Passt sie zu genau einem Eintrag, ist dein Zugang gleich mit ihm verknüpft. Ohne geht es auch.',
    note: 'Wie gehörst du zur Familie? (freiwillig)',
    noteHint:
      'Zwei Sätze genügen — etwa die Eltern oder Großeltern, über die du dazugehörst. Das hilft der Person, die deinen Antrag liest.',
    submit: 'Antrag absenden',
    sending: 'Wird gesendet …',
    missing: 'Bitte gib deinen Namen und deine E-Mail-Adresse ein.',
    sent: {
      title: 'Dein Antrag ist angekommen',
      body:
        'Die Familienverwaltung sieht ihn sich an und meldet sich bei dir. Das kann ein paar Tage dauern — es liest ein Mensch, keine Maschine. Du musst nichts weiter tun.',
    },
    haveAccount: 'Du hast schon einen Zugang?',
    backToLogin: 'Zur Anmeldung',
  },
  lists: {
    title: 'Rundmails der Familie',
    intro:
      'Du bekommst diese Rundmails an {{address}} — die Adresse deines Zugangs. Du kannst jederzeit eine abbestellen und ebenso jederzeit wieder dazukommen.',
    pending: 'Deine Antwort ist notiert und wird gerade übernommen.',
    failed:
      'Deine Antwort ist notiert, ließ sich aber noch nicht übernehmen. Wir kümmern uns darum — du musst nichts weiter tun.',
    noAddress:
      'Zu deinem Zugang ist keine E-Mail-Adresse hinterlegt, deshalb gibt es keine Adresse, an die die Post der Familie gehen könnte. Wende dich an die Person, die den Familienstammbaum betreut.',
  },
  directoryPrompt: {
    title: 'Im Mitgliederverzeichnis erscheinen?',
    body:
      'Andere angemeldete Familienmitglieder sehen dann deinen Namen im Verzeichnis und können dir schreiben, ohne dass jemand deine Adresse erfährt. Aus dem Stammbaum wird dadurch nichts zusätzlich sichtbar.',
    hint: 'Du kannst das jederzeit in den Einstellungen wieder ändern.',
    yes: 'Ja, anzeigen',
    no: 'Nein, nicht anzeigen',
  },
  settings: {
    contacts: 'Meine Kontakte',
    contactsBody:
      'Verbinde dich mit einzelnen Mitgliedern — beim Familientreffen über einen QR-Code oder über die SB-Nummer. Deine Kontakte sind eine eigene Gruppe, für die du Kontaktdaten freigeben kannst.',
    contactsAction: 'Kontakte verwalten',
    title: 'Einstellungen',
    contact: 'Meine Kontaktdaten',
    invite: 'Familie einladen',
    inviteBody:
      'Du kannst deiner engen Familie einen Zugang zum Portal einrichten. Du bekommst einen Link und schickst ihn selbst weiter.',
    inviteAction: 'Jemanden einladen',
    language: 'Sprache',
    languageHint: 'Gilt für dieses Gerät.',
    languageAccountHint:
      'Gilt für dein Konto — also auch auf jedem anderen Gerät und für E-Mails aus dem Stammbaum.',
    account: 'Konto',
    directory: 'Verzeichnis',
    directoryVisible: 'Du bist im Mitgliederverzeichnis sichtbar.',
    directoryHidden: 'Du bist im Mitgliederverzeichnis nicht sichtbar.',
    directoryToggle: 'Im Mitgliederverzeichnis anzeigen',
    directoryExplain:
      'Andere angemeldete Mitglieder sehen dann deinen Namen. Du kannst das jederzeit wieder ausschalten.',
    displayName: 'Angezeigter Name',
    displayNameHint: 'Leer lassen, um deinen normalen Namen zu verwenden.',
    save: 'Speichern',
    saving: 'Wird gespeichert …',
    saved: 'Gespeichert.',
    logout: 'Abmelden',
    tree: 'Stammbaum',
  },
  individual: {
    born: 'Geboren',
    died: 'Gestorben',
    events: 'Lebensdaten',
    parents: 'Eltern',
    siblings: 'Geschwister',
    spouses: 'Partnerinnen und Partner',
    children: 'Kinder',
    reference: 'SB-Nummer im Familienarchiv',
    branch: 'Zweig der Familie',
    relationship: 'Für dich: {{relationship}}',
    showAncestors: 'Vorfahren anzeigen',
    editInWebtrees: 'In webtrees öffnen und bearbeiten',
    editInWebtreesHint: 'Du siehst diesen Link, weil du den Stammbaum bearbeiten darfst.',
    photos: 'Fotos',
    photoUntitled: 'Foto ohne Titel',
    photoClose: 'Schließen',
    noEvents: 'Zu dieser Person sind keine weiteren Daten hinterlegt.',
    notVisible: 'Keine Angaben aus dem Stammbaum sichtbar',
    sex: {
      M: 'männlich',
      F: 'weiblich',
      X: 'divers',
      U: 'unbekannt',
    },
  },
  link: {
    copy: 'Kopieren',
    share: 'Teilen',
    copied: 'Der Link ist kopiert. Füge ihn in deine Nachricht ein.',
  },
  myPhotos: {
    title: 'Meine Fotos',
    body: 'Du kannst ein Foto von dir hinzufügen. Es erscheint auf deinem Datensatz und wird allen Mitgliedern gezeigt, die dich sehen dürfen.',
    rule:
      'Fotos lebender Personen werden im Portal nur gezeigt, wenn die Person sie selbst hochgeladen hat — auch dann, wenn im Stammbaum weitere liegen. Fotos Verstorbener bleiben unverändert.',
    choose: 'Foto auswählen',
    hint: 'JPEG, PNG oder WebP, höchstens 4 MB. Aufnahmeort und andere versteckte Angaben werden beim Hochladen entfernt.',
    remove: 'Entfernen',
    untitled: 'Foto',
    waiting:
      'Das Foto ist gespeichert. Weil an deinem Datensatz noch eine Änderung auf Freigabe wartet, erscheint es erst, wenn diese freigegeben ist.',
  },
  person: {
    title: 'Person',
    backToProfile: 'Zurück zu meinem Profil',
    connect: {
      title: 'Verbinden',
      body:
        'Wenn {{name}} ein Konto im Portal hat, bekommt sie oder er deine Anfrage und entscheidet selbst. Verbunden könnt ihr euch schreiben und die Kontaktdaten sehen, die ihr für eure Kontakte freigegeben habt.',
      action: 'Verbinden',
      quiet:
        'Wenn {{name}} ein Konto im Portal hat, ist deine Anfrage unterwegs. Du erfährst erst davon, wenn sie angenommen wird — dann steht der Kontakt unter „Kontakte".',
      waiting:
        'Du hast hier bereits angefragt. Wenn {{name}} ein Konto im Portal hat und die Anfrage annimmt, erscheint der Kontakt unter „Kontakte".',
      again: 'Nochmal anfragen',
      connected: 'Ihr seid verbunden.',
    },
    invite: {
      title: 'Noch nicht im Portal',
      body: '{{name}} hat noch keinen Zugang. Du kannst eine Einladung erstellen und den Link selbst weitergeben.',
      action: 'Einladen',
    },
  },
  ancestors: {
    title: 'Vorfahren',
    generation: {
      1: 'Eltern',
      2: 'Großeltern',
      3: 'Urgroßeltern',
      4: 'Ururgroßeltern',
      nth: '{{n}}. Generation',
    },
    path: {
      your: {
        father: 'Dein Vater',
        mother: 'Deine Mutter',
      },
      possessive: {
        father: 'Vaters',
        mother: 'Mutters',
      },
      final: {
        father: 'Vater',
        mother: 'Mutter',
      },
      step: {
        father: 'Vater',
        mother: 'Mutter',
      },
    },
    none: {
      title: 'Keine Vorfahren hinterlegt',
      body: 'Zu dieser Person sind im Stammbaum keine Eltern erfasst.',
    },
    private: {
      name: 'Nicht freigegeben',
      member: 'Im Mitgliederverzeichnis eingetragen',
    },
    truncated:
      'Der Stammbaum reicht noch weiter zurück, als hier gezeigt werden kann. Über eine der obersten Personen geht es weiter.',
    privacyNote:
      'Wo eine Person für dich nicht freigegeben ist — das sind fast immer die Lebenden — steht nur, dass dort jemand steht: kein Name, keine Daten. Wer sich selbst ins Mitgliederverzeichnis eingetragen hat, wird mit dem Namen aus dem Verzeichnis genannt; aus dem Stammbaum wird auch dann nichts angezeigt.',
  },
  error: {
    title: 'Da ist etwas schiefgelaufen',
    reference: 'Wenn du nachfragst, nenne bitte diese Kennung:',
    retry: 'Noch einmal versuchen',
    network: 'Das Portal konnte den Server nicht erreichen. Bitte prüfe deine Internetverbindung.',
    not_found: 'Dieser Eintrag wurde nicht gefunden. Vielleicht ist er nicht mehr für dich freigegeben.',
    not_configured:
      'Das Portal ist noch nicht vollständig eingerichtet. Bitte wende dich an die Familienverwaltung.',
    server_error: 'Auf dem Server ist ein Fehler aufgetreten. Bitte versuche es später noch einmal.',
    unreadable_answer:
      'Der Familienserver ist gerade überlastet oder wird gewartet. Es liegt nicht an deinen Zugangsdaten — bitte versuche es in ein paar Minuten noch einmal.',
    record_locked:
      'Dieser Eintrag ist gesperrt und kann nicht geändert werden. Bitte wende dich an die Familienverwaltung.',
    change_pending:
      'Eine frühere Änderung von dir wartet noch auf die Freigabe. Bitte warte, bis sie geprüft wurde.',
    no_linked_record: 'Dein Konto ist noch mit keiner Person im Stammbaum verknüpft.',
    cannot_reply:
      'Auf diese Nachricht kann hier nicht geantwortet werden — die Absenderadresse gehört zu keinem Konto im Stammbaum.',
    unknown: 'Bitte versuche es noch einmal.',
    pageNotFound: {
      title: 'Diese Seite gibt es nicht',
      body: 'Vielleicht hat sich die Adresse geändert.',
      action: 'Zu meinem Profil',
    },
  },
  common: {
    office: 'Amt in der Stiftung',
    loading: 'Wird geladen …',
    newWindow: 'öffnet in einem neuen Fenster',
  },
}

export type Translations = typeof de
