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
    intro: 'Melden Sie sich mit Ihrem Benutzernamen und Passwort an.',
    username: 'Benutzername oder E-Mail-Adresse',
    password: 'Passwort',
    submit: 'Anmelden',
    submitting: 'Einen Moment …',
    remember: 'Angemeldet bleiben',
    rememberHint_one:
      'Sie bleiben auf diesem Gerät einen Tag angemeldet und müssen Ihr Passwort so lange nicht wieder eingeben. Wer das Gerät entsperrt in die Hand bekommt, ist dann Sie — schalten Sie das also nur auf Ihrem eigenen Telefon ein. „Abmelden“ in den Einstellungen beendet es sofort.',
    rememberHint_other:
      'Sie bleiben auf diesem Gerät {{count}} Tage angemeldet und müssen Ihr Passwort so lange nicht wieder eingeben. Wer das Gerät entsperrt in die Hand bekommt, ist dann Sie — schalten Sie das also nur auf Ihrem eigenen Telefon ein. „Abmelden“ in den Einstellungen beendet es sofort.',
    forgotten: 'Passwort vergessen?',
    noAccount: 'Noch keinen Zugang? Hier beantragen',
    failed: 'Benutzername oder Passwort ist falsch. Bitte versuchen Sie es noch einmal.',
    missing: 'Bitte füllen Sie beide Felder aus.',
  },
  password: {
    requestTitle: 'Passwort zurücksetzen',
    requestIntro:
      'Geben Sie Ihre E-Mail-Adresse ein. Wenn dazu ein Konto gehört, schicken wir Ihnen einen Link zum Zurücksetzen.',
    email: 'E-Mail-Adresse',
    send: 'Link anfordern',
    sending: 'Wird gesendet …',
    sent: {
      title: 'Bitte sehen Sie in Ihr Postfach',
      body:
        'Falls zu dieser Adresse ein Konto gehört, ist der Link unterwegs. Er gilt eine Stunde. Sehen Sie auch im Spam-Ordner nach.',
    },
    backToLogin: 'Zurück zur Anmeldung',
    resetTitle: 'Neues Passwort festlegen',
    resetIntro: 'Wählen Sie ein Passwort mit mindestens acht Zeichen.',
    newPassword: 'Neues Passwort',
    repeatPassword: 'Passwort wiederholen',
    save: 'Passwort speichern',
    saving: 'Wird gespeichert …',
    mismatch: 'Die beiden Passwörter stimmen nicht überein.',
    tooShort: 'Das Passwort muss mindestens acht Zeichen lang sein.',
    missingToken: {
      title: 'Dieser Link ist unvollständig',
      body: 'Bitte öffnen Sie den Link aus der E-Mail noch einmal oder fordern Sie einen neuen an.',
      action: 'Neuen Link anfordern',
    },
    expired: 'Dieser Link ist abgelaufen oder wurde schon benutzt. Bitte fordern Sie einen neuen an.',
  },
  invitation: {
    title: 'Willkommen in der Familie',
    intro:
      'Sie wurden zum Mitgliederportal von „{{tree}}“ eingeladen. Legen Sie hier Ihren Zugang an — das dauert eine Minute.',
    invitedAs: 'Die Einladung gilt für:',
    realName: 'Ihr Name',
    username: 'Benutzername',
    usernameHint:
      'Damit melden Sie sich künftig an. Mindestens drei Zeichen, ohne Leerzeichen und ohne „@“.',
    email: 'E-Mail-Adresse',
    emailHint:
      'Hierhin geht der Link, falls Sie einmal Ihr Passwort vergessen. Sie muss nicht die Adresse sein, an die die Einladung ging.',
    passwordHint: 'Mindestens acht Zeichen.',
    save: 'Zugang anlegen',
    saving: 'Wird angelegt …',
    usernameTaken:
      'Diesen Benutzernamen gibt es schon. Bitte wählen Sie einen anderen — Ihre Einladung gilt weiterhin.',
    emailTaken:
      'Zu dieser E-Mail-Adresse gibt es schon ein Konto. Bitte nehmen Sie eine andere Adresse oder melden Sie sich mit dem vorhandenen Konto an.',
    badDetails: 'Bitte prüfen Sie Ihre Angaben. Etwas davon konnte der Server nicht annehmen.',
    privacyNote:
      'Im Portal sehen Sie nur, was für Sie freigegeben ist. Angaben zu lebenden Personen bleiben geschützt.',
    unusable: {
      title: 'Diese Einladung gilt nicht mehr',
      body:
        'Der Link ist abgelaufen oder wurde schon benutzt. Bitte fragen Sie in der Familienverwaltung nach einer neuen Einladung.',
      action: 'Zur Anmeldung',
    },
  },
  invite: {
    title: 'Jemanden einladen',
    intro:
      'Sie können Ihrer engen Familie einen Zugang zum Portal einrichten. Sie wählen die Person aus, bekommen einen Link und schicken ihn selbst — so, wie Sie diese Person sonst auch erreichen.',
    chooseTitle: 'Wen möchten Sie einladen?',
    findLegend: 'Person suchen',
    findHint:
      'Name, Spitzname oder SB-Nr. Sie können jede Person einladen, die Sie sehen können.',
    findResults: 'Gefundene Personen',
    findNone: 'Für „{{query}}" wurde niemand gefunden.',
    chosen: 'Ausgewählt: {{name}}',
    whoLegend: 'Person auswählen',
    whoPlaceholder: 'Bitte auswählen …',
    email: 'E-Mail-Adresse (freiwillig)',
    emailHint:
      'Wird nur für sie ins Formular vorausgefüllt. Der Link geht nicht automatisch raus — den verschicken Sie selbst.',
    create: 'Einladung erstellen',
    creating: 'Wird erstellt …',
    remaining_one: 'Sie können noch {{count}} Einladung offen haben.',
    remaining_other: 'Sie können noch {{count}} Einladungen offen haben.',
    pickSomebody: 'Bitte wählen Sie zuerst eine Person aus.',
    refused:
      'Diese Person können Sie nicht einladen. Vielleicht hat sie schon einen Zugang oder ist bereits eingeladen.',
    ready: {
      shareTitle: 'Einladung zur Sack Familienapp',
      shareText: 'Du bist in unsere Familien-App eingeladen. Über diesen Link kannst du dein Konto einrichten:',
      title: 'Die Einladung ist fertig',
      body:
        'Kopieren Sie den Link und schicken Sie ihn dieser Person — per Nachricht, E-Mail oder wie Sie mögen. Der Link wird nur dieses eine Mal angezeigt und lässt sich später nicht wieder aufrufen. Falls er verloren geht: Einladung zurücknehmen und eine neue erstellen.',
      label: 'Einladungslink',
      done: 'Habe ich kopiert',
    },
    outstandingTitle: 'Ihre offenen Einladungen',
    expires: 'Gültig bis {{date}}',
    withdraw: 'Zurücknehmen',
    none: {
      title: 'Zurzeit niemand zum Einladen',
      body:
        'Alle Ihre nahen Angehörigen sind schon dabei, bereits eingeladen, oder im Stammbaum als verstorben eingetragen.',
    },
    quota: {
      title: 'Sie haben schon genug offene Einladungen',
      body:
        'Nehmen Sie eine offene Einladung zurück oder warten Sie, bis sie benutzt wurde — dann geht es weiter.',
    },
    off: {
      title: 'Einladungen durch Mitglieder sind ausgeschaltet',
      body: 'In dieser Familie legt die Familienverwaltung die Zugänge an. Fragen Sie dort nach.',
    },
    noRecord: {
      title: 'Ihr Konto ist noch nicht verknüpft',
      body:
        'Solange Ihr Konto mit keiner Person im Stammbaum verbunden ist, weiß das Portal nicht, wer Ihre Familie ist. Bitte wenden Sie sich an die Familienverwaltung.',
    },
  },
  contact: {
    intro:
      'Tragen Sie ein, was Sie erreichbar macht — und entscheiden Sie für jeden Eintrag einzeln, wer ihn sehen darf. Was Sie leer lassen, wird nicht geteilt.',
    keptHint:
      '„Niemand“ heißt: Der Eintrag bleibt gespeichert — etwa für den Versand der Familienzeitschrift — wird aber niemandem im Portal gezeigt. Zum endgültigen Löschen leeren Sie das Feld und speichern.',
    keptNote: 'Gespeichert, aber niemandem gezeigt.',
    kind: {
      email: 'E-Mail-Adresse',
      phone: 'Telefonnummer',
      address: 'Anschrift',
    },
    hint: {
      email: 'Kann eine andere sein als die, mit der Sie sich anmelden.',
      phone: 'So, wie Sie sie jemandem am Telefon nennen würden.',
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
    summaryIntro: 'Das teilen Sie zurzeit. Sie können es jederzeit ändern oder wieder löschen.',
    empty: 'Nicht angegeben',
    sharedWith: 'Sichtbar für: {{audience}}',
    change: 'Kontaktdaten ändern',
    cancel: 'Abbrechen',
    save: 'Kontaktdaten speichern',
    saving: 'Wird gespeichert …',
    saved: 'Ihre Kontaktdaten sind gespeichert.',
    sharedTitle: 'Kontakt',
    off: {
      title: 'Kontaktdaten sind ausgeschaltet',
      body: 'In dieser Familie werden über das Portal keine Kontaktdaten geteilt.',
    },
  },
  conversation: {
    noneTitle: 'Noch keine Gespräche',
    noneBody: 'Wählen Sie jemanden aus Ihren Kontakten aus, und schreiben Sie los.',
    noneAction: 'Gespräch beginnen',
    listTitle: 'Gespräche',
    start: 'Neues Gespräch',
    back: 'Zurück zu den Nachrichten',
    profile: 'Zum Profil',
    empty: 'Noch nichts gesagt. Schreiben Sie die erste Nachricht.',
    write: 'Ihre Nachricht',
    send: 'Senden',
    sending: 'Wird gesendet …',
    read: 'gelesen',
    unread: '{{count}} ungelesen',
    you: 'Sie',
    delete: 'Für mich löschen',
    deleteExplain:
      'Die Nachricht verschwindet nur bei Ihnen. Die andere Person behält ihre Kopie.',
    deleteConfirm: 'Löschen',
    notifyNotice:
      'Die andere Person erfährt nur, dass eine Nachricht im Portal wartet — weder Ihr Name noch der Text stehen in der Benachrichtigung.',
  },
  newConversation: {
    title: 'Neues Gespräch',
    noneTitle: 'Noch keine Kontakte',
    noneBody:
      'Ein Gespräch beginnt mit jemandem aus Ihren Kontakten. Legen Sie zuerst einen Kontakt an.',
    noneAction: 'Zu meinen Kontakten',
    filter: 'Name suchen',
    noMatch: 'Kein Kontakt mit diesem Namen.',
    elsewhere: 'Jemand, der nicht in Ihren Kontakten steht?',
    elsewhereAction: 'Im Mitgliederverzeichnis suchen',
  },
  message: {
    title: 'Nachricht an {{name}}',
    subject: 'Betreff',
    body: 'Ihre Nachricht',
    send: 'Nachricht senden',
    open: 'Nachricht schreiben',
    opening: 'Wird geöffnet …',
    sending: 'Wird gesendet …',
    sent: 'Ihre Nachricht ist unterwegs.',
    notifyNotice:
      'Die andere Person erfährt nur, dass eine Nachricht im Portal wartet — weder Ihr Name noch der Text stehen in der Benachrichtigung. Ihre E-Mail-Adresse wird nicht mitgeschickt.',
  },
  messages: {
    inboxTitle: 'Sonstige Nachrichten',
    title: 'Nachrichten',
    unread: 'ungelesen',
    markUnread: 'Als ungelesen markieren',
    reply: 'Antworten',
    replyAddressNotice:
      'Damit man Ihnen antworten kann, wird Ihre E-Mail-Adresse als Absenderadresse mitgeschickt — auch dann, wenn Sie sie in Ihren Kontaktdaten nicht freigegeben haben.',
    replyLabel: 'Ihre Antwort',
    replyCancel: 'Abbrechen',
    replySend: 'Antwort senden',
    replySending: 'Wird gesendet …',
    replySent: 'Ihre Antwort ist unterwegs. Eine Kopie wird hier nicht aufbewahrt.',
    replyImpossible:
      'Auf diese Nachricht kann hier nicht geantwortet werden — die Absenderadresse gehört zu keinem Konto im Stammbaum.',
    delete: 'Löschen',
    none: {
      title: 'Keine Nachrichten',
      body: 'Hier erscheinen Nachrichten, die Ihnen andere Mitglieder oder die Familienverwaltung schicken.',
    },
    note:
      'Das ist Ihr Postfach in webtrees — dieselben Nachrichten, die Sie auch dort sehen. Was Sie hier löschen, ist auch dort gelöscht.',
  },
  profile: {
    title: 'Mein Profil',
    noRecord: {
      title: 'Ihr Eintrag im Stammbaum fehlt noch',
      body: 'Ihr Konto ist noch mit keiner Person im Stammbaum verknüpft. Die Familienverwaltung kann das einrichten.',
    },
    edit: 'Meine Daten ändern',
    openInWebtrees: 'Stammbaum und Diagramme öffnen',
    pending: {
      title: 'Ihre Änderung wird geprüft',
      body:
        'Ihre Angaben wurden weitergegeben. Bis jemand aus der Familienverwaltung sie freigibt, sehen Sie hier weiterhin den bisherigen Stand.',
    },
  },
  edit: {
    title: 'Meine Daten ändern',
    intro:
      'Ihre Änderungen werden nicht sofort übernommen. Die Familienverwaltung sieht sie sich an und gibt sie frei.',
    section: {
      name: 'Name',
      birth: 'Geburt',
      work: 'Beruf',
      contact: 'Kontakt',
    },
    givenNames: 'Vorname',
    surname: 'Nachname',
    birthDate: 'Geburtsdatum',
    birthDateStored: 'Bisher gespeichert: {{date}}. Dieses Datum bleibt unverändert, solange Sie hier keines auswählen.',
    birthPlace: 'Geburtsort',
    occupation: 'Beruf',
    address: 'Anschrift',
    email: 'E-Mail-Adresse',
    phone: 'Telefon',
    website: 'Webseite',
    contactHint: 'Ihre Kontaktdaten sehen nur Sie selbst und die Familienverwaltung.',
    submit: 'Änderung einreichen',
    submitting: 'Wird eingereicht …',
    cancel: 'Abbrechen',
    unchanged: 'Sie haben nichts geändert.',
    applied: {
      body: 'Ihre Änderung wurde übernommen und ist im Stammbaum sichtbar.',
    },
    submitted: {
      title: 'Danke — wir haben Ihre Änderung erhalten',
      body: 'Sie wird geprüft und danach übernommen. Sie müssen nichts weiter tun.',
      action: 'Zurück zum Profil',
    },
    blocked: {
      title: 'Eine Änderung wartet noch',
      body:
        'Sie haben bereits eine Änderung eingereicht, die noch nicht freigegeben ist. Bitte warten Sie, bis sie geprüft wurde.',
    },
    locked: 'Dieser Eintrag ist gesperrt und kann nicht geändert werden. Bitte wenden Sie sich an die Familienverwaltung.',
    noRecord: 'Ihr Konto ist noch mit keiner Person im Stammbaum verknüpft.',
  },
  contacts: {
    title: 'Meine Kontakte',
    tabs: 'Kontakte und neue Verbindungen',
    tabMine: 'Kontakte',
    tabNew: 'Neu verbinden',
    intro:
      'Hier stehen die Menschen, mit denen Sie sich verbunden haben. Eine Verbindung entsteht immer zu zweit — und Sie können sie jederzeit wieder lösen.',
    find: 'Jemanden im Portal suchen',
    findBody:
      'Alle Mitglieder, die einer Anzeige zugestimmt haben. Suchen Sie nach einem Namen, oder lassen Sie das Feld leer, um alle zu sehen.',
    findLabel: 'Name',
    findAction: 'Im Verzeichnis suchen',
    showCode: 'Sich vor Ort verbinden',
    codeBody:
      'Zeigen Sie diesen Code auf Ihrem Bildschirm. Die andere Person hält einfach ihre Handykamera darauf — dann sind Sie beide verbunden. Der Code gilt {{count}} Minuten.',
    codeShow: 'Code anzeigen',
    codeRenew: 'Neuen Code erzeugen',
    codeHide: 'Code ungültig machen',
    codeHidden: 'Der Code ist ungültig. Sie können jederzeit einen neuen erzeugen.',
    codeValid: 'Gilt noch etwa {{count}} Minuten. Danach brauchen Sie einen neuen Code.',
    codeAlt: 'QR-Code, mit dem sich jemand mit Ihnen verbinden kann',
    sendLink: 'Einen Link verschicken',
    linkBody:
      'Wenn Sie die Person schon erreichen können — per E-Mail, Messenger, SMS —, schicken Sie ihr einen Link. Wer ihn öffnet und auf „Verbinden" tippt, ist mit Ihnen verbunden. Der Link gilt {{count}} Tage.',
    linkCreate: 'Link erzeugen',
    linkAnother: 'Weiteren Link erzeugen',
    linkLabel: 'Ihr Link',
    linkShareTitle: 'Im Familienportal verbinden',
    linkOnce:
      'Der Link funktioniert genau einmal und läuft nach {{count}} Tagen ab. Schicken Sie ihn nur der einen Person — wer ihn sonst in die Hände bekommt, wäre mit Ihnen verbunden.',
    linkOpen: 'Verschickte Links, die noch niemand benutzt hat',
    linkExpires: 'Gültig bis {{date}}',
    linkWithdraw: 'Zurückziehen',
    linkOpenHint:
      'An wen Sie einen Link geschickt haben, weiß das Portal nicht — das haben Sie selbst getan. Wer ihn benutzt, erscheint oben als Kontakt.',
    byReference: 'Mit der SB-Nummer verbinden',
    referenceBody:
      'Die SB-Nummer steht im Portal unter dem Namen der Person, zum Beispiel „10/1335.21“. Die andere Person bekommt eine Anfrage und entscheidet selbst — auch dann, wenn sie im Mitgliederverzeichnis nicht sichtbar ist.',
    kinship: 'Für Sie: {{relationship}}',
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
      'Wenn diese Nummer zu einem Mitglied gehört, ist Ihre Anfrage unterwegs. Sie erfahren erst davon, wenn die Anfrage angenommen wird — dann steht der Kontakt hier.',
    requested: 'Ihre Anfrage ist bei {{name}} — sobald sie bestätigt wird, erscheint der Kontakt hier.',
    alreadyConnected: 'Diese Nummer gehört zu {{name}} — Sie sind bereits verbunden. Es wurde nichts geschickt.',
    connected: 'Sie sind jetzt mit {{name}} verbunden.',
    incoming: 'Anfragen an Sie',
    outgoing: 'Ihre Anfragen',
    waiting: 'Wartet auf eine Antwort.',
    asksYou: 'möchte sich mit Ihnen verbinden.',
    asksYouAs: 'möchte sich mit Ihnen verbinden — im Stammbaum: {{name}}.',
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
      none: 'Sie sind mit dieser Person noch nicht verbunden.',
      requested: 'Ihre Anfrage ist unterwegs und wartet auf eine Antwort.',
      incoming: '{{name}} möchte sich mit Ihnen verbinden.',
      connected: 'Sie sind verbunden.',
    },
    none: {
      title: 'Noch keine Kontakte',
      body: 'Verbinden Sie sich beim nächsten Familientreffen mit dem Code oben — oder über die SB-Nummer, wenn Ihnen jemand seine genannt hat.',
    },
    off: {
      title: 'Verbindungen sind ausgeschaltet',
      body: 'In dieser Familie werden über das Portal keine neuen Verbindungen geknüpft. Bestehende Kontakte können Sie weiterhin sehen.',
    },
  },
  connect: {
    title: 'Verbinden',
    intro:
      'Sie haben einen Verbindungscode geöffnet. Wenn Sie fortfahren, sind Sie und die Person, die den Code gezeigt hat, miteinander verbunden.',
    connect: 'Jetzt verbinden',
    connecting: 'Einen Moment …',
    done: 'Sie sind jetzt mit {{name}} verbunden.',
    toContacts: 'Zu meinen Kontakten',
    missing: {
      title: 'Dieser Link ist unvollständig',
      body: 'Bitte scannen Sie den Code noch einmal. Falls das nicht klappt, lassen Sie sich einen neuen Code zeigen.',
    },
  },
  tree: {
    title: 'Stammbaum',
    intro:
      'Suchen Sie im Familienarchiv nach einem Namen oder einer SB-Nr. — oder blättern Sie durch die Namen und die Orte.',
    tabSearch: 'Suche',
    tabCalculator: 'Rechner',
    calc: {
      intro:
        'Die SB-Nr. ist keine Bezeichnung, sondern ein Weg: Linie, dann ein Zeichen je Generation. Deshalb lässt sich aus zwei Nummern allein der Verwandtschaftsgrad berechnen — ohne Daten, ohne Grenze, auch für Personen, die gar nicht im Stammbaum stehen.',
      first: 'SB-Nr. 1',
      firstHint: 'Ihre eigene Nummer ist schon eingetragen. Sie können sie überschreiben.',
      second: 'SB-Nr. 2',
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
      body: 'Für „{{query}}" wurde niemand gefunden. Versuchen Sie einen kürzeren Suchbegriff.',
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
        body: 'Im Stammbaum ist für Sie noch kein Name sichtbar.',
      },
    },
    places: {
      empty: {
        title: 'Noch keine Orte',
        body: 'Im Stammbaum ist für Sie noch kein Ort sichtbar.',
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
      body: 'Für „{{query}}" wurde niemand gefunden. Versuchen Sie einen kürzeren Suchbegriff.',
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
      body: 'Zu diesem Mitglied sind für Sie keine Daten aus dem Stammbaum freigegeben.',
    },
  },
  install: {
    title: 'Auf den Startbildschirm',
    body:
      'Sie können die Sack Familienapp auf den Startbildschirm legen. Sie öffnet sich dann mit einem Tippen, ohne Adresszeile und ohne Suchen im Browser.',
    action: 'Auf den Startbildschirm legen',
    apple: 'Tippen Sie unten auf das Teilen-Symbol und dann auf „Zum Home-Bildschirm“.',
    appleOther:
      'Tippen Sie oben auf das Teilen-Symbol und dann auf „Zum Home-Bildschirm“. In Safari sitzt dieses Symbol unten.',
    android:
      'Tippen Sie oben rechts auf die drei Punkte und dann auf „App installieren“ — je nach Version heißt es auch „Zum Startbildschirm hinzufügen“.',
    webview:
      'Diese Seite wurde gerade in einer anderen App geöffnet, die das nicht kann. Tippen Sie auf die drei Punkte und dann auf „Im Browser öffnen“ — dort geht es.',
    done: 'Die App liegt bereits auf dem Startbildschirm dieses Geräts.',
    later: 'Später',
    understood: 'Alles klar',
    staysInSettings: 'Sie finden das jederzeit wieder unter „Einstellungen“.',
  },
  notifications: {
    title: 'Benachrichtigungen',
    body:
      'Sie können sich benachrichtigen lassen, wenn eine neue Nachricht ankommt — auch wenn die App gerade nicht offen ist.',
    privacy:
      'Auf dem Sperrbildschirm steht nur, dass eine Nachricht da ist. Weder der Name der Person noch der Text werden angezeigt.',
    switchOn: 'Benachrichtigungen einschalten',
    switchOff: 'Auf diesem Gerät ausschalten',
    working: 'Einen Moment …',
    on: 'Dieses Gerät wird benachrichtigt.',
    untilSignOut:
      'Beim Abmelden wird dieses Gerät abgemeldet. Sobald Sie sich hier wieder anmelden, schaltet es sich von selbst wieder ein — bis Sie oben auf „Auf diesem Gerät ausschalten“ tippen.',
    needsInstall:
      'Auf dem iPhone und iPad gibt es Benachrichtigungen nur, wenn die App auf dem Home-Bildschirm liegt. Wie Sie sie dorthin legen, steht oben auf dieser Seite unter „Auf den Startbildschirm“. Danach können Sie die Benachrichtigungen hier einschalten.',
    blocked:
      'Ihr Browser blockiert Benachrichtigungen für diese Seite. Das lässt sich nur in den Browser-Einstellungen wieder erlauben.',
  },
  claim: {
    title: 'Zum Familienportal anmelden',
    intro:
      'Sie haben diesen Link aus einem Rundschreiben der Familie — einer Rundmail oder der Familienzeitschrift. Geben Sie hier die E-Mail-Adresse ein, unter der die Familie Sie erreicht; Ihre persönliche Einladung schicken wir dann genau dorthin.',
    email: 'Ihre E-Mail-Adresse',
    emailHint:
      'Die Adresse, unter der Sie Post von der Familie bekommen. An eine andere können wir nichts schicken.',
    submit: 'Einladung anfordern',
    sending: 'Wird gesendet …',
    missing: 'Bitte geben Sie Ihre E-Mail-Adresse ein.',
    sent: {
      title: 'Bitte sehen Sie in Ihr Postfach',
      body:
        'Wenn diese Adresse zur Familie gehört, ist Ihre persönliche Einladung unterwegs. Der Link darin gilt nur für Sie und nur einmal — bitte geben Sie ihn nicht weiter. Sehen Sie auch im Spam-Ordner nach.',
    },
    noMail: 'Sie bekommen keine Post von der Familie an eine E-Mail-Adresse?',
    askInstead: 'Zugang beantragen',
    haveAccount: 'Sie haben schon einen Zugang?',
    backToLogin: 'Zur Anmeldung',
  },
  request: {
    title: 'Zugang zum Familienportal beantragen',
    intro:
      'Sagen Sie uns kurz, wer Sie sind. Wir legen nichts an und verschicken nichts — Ihre Angaben landen bei der Familienverwaltung, und ein Mensch entscheidet und schreibt Ihnen.',
    name: 'Ihr Name',
    nameHint: 'So, wie die Familie Sie kennt.',
    email: 'Ihre E-Mail-Adresse',
    emailHint: 'Dorthin ginge Ihre Einladung, wenn eine ausgestellt wird.',
    reference: 'Ihre SB-Nummer (wenn Sie sie kennen)',
    referenceHint:
      'Die Nummer, die in der Familienzeitschrift neben Ihrem Namen steht, zum Beispiel 22/1a32.124. Passt sie zu genau einem Eintrag, ist Ihr Zugang gleich mit ihm verknüpft. Ohne geht es auch.',
    note: 'Wie gehören Sie zur Familie? (freiwillig)',
    noteHint:
      'Zwei Sätze genügen — etwa die Eltern oder Großeltern, über die Sie dazugehören. Das hilft der Person, die Ihren Antrag liest.',
    submit: 'Antrag absenden',
    sending: 'Wird gesendet …',
    missing: 'Bitte geben Sie Ihren Namen und Ihre E-Mail-Adresse ein.',
    sent: {
      title: 'Ihr Antrag ist angekommen',
      body:
        'Die Familienverwaltung sieht ihn sich an und meldet sich bei Ihnen. Das kann ein paar Tage dauern — es liest ein Mensch, keine Maschine. Sie müssen nichts weiter tun.',
    },
    haveAccount: 'Sie haben schon einen Zugang?',
    backToLogin: 'Zur Anmeldung',
  },
  lists: {
    title: 'Rundmails der Familie',
    intro:
      'Sie bekommen diese Rundmails an {{address}} — die Adresse Ihres Zugangs. Sie können jederzeit eine abbestellen und ebenso jederzeit wieder dazukommen.',
    pending: 'Ihre Antwort ist notiert und wird gerade übernommen.',
    failed:
      'Ihre Antwort ist notiert, ließ sich aber noch nicht übernehmen. Wir kümmern uns darum — Sie müssen nichts weiter tun.',
    noAddress:
      'Zu Ihrem Zugang ist keine E-Mail-Adresse hinterlegt, deshalb gibt es keine Adresse, an die die Post der Familie gehen könnte. Wenden Sie sich an die Person, die den Familienstammbaum betreut.',
  },
  settings: {
    contacts: 'Meine Kontakte',
    contactsBody:
      'Verbinden Sie sich mit einzelnen Mitgliedern — beim Familientreffen über einen QR-Code oder über die SB-Nummer. Ihre Kontakte sind eine eigene Gruppe, für die Sie Kontaktdaten freigeben können.',
    contactsAction: 'Kontakte verwalten',
    title: 'Einstellungen',
    contact: 'Meine Kontaktdaten',
    invite: 'Familie einladen',
    inviteBody:
      'Sie können Ihrer engen Familie einen Zugang zum Portal einrichten. Sie bekommen einen Link und schicken ihn selbst weiter.',
    inviteAction: 'Jemanden einladen',
    language: 'Sprache',
    languageHint: 'Gilt für dieses Gerät.',
    languageAccountHint:
      'Gilt für Ihr Konto — also auch auf jedem anderen Gerät und für E-Mails aus dem Stammbaum.',
    account: 'Konto',
    directory: 'Verzeichnis',
    directoryVisible: 'Sie sind im Mitgliederverzeichnis sichtbar.',
    directoryHidden: 'Sie sind im Mitgliederverzeichnis nicht sichtbar.',
    directoryToggle: 'Im Mitgliederverzeichnis anzeigen',
    directoryExplain:
      'Andere angemeldete Mitglieder sehen dann Ihren Namen. Sie können das jederzeit wieder ausschalten.',
    displayName: 'Angezeigter Name',
    displayNameHint: 'Leer lassen, um Ihren normalen Namen zu verwenden.',
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
    relationship: 'Für Sie: {{relationship}}',
    showAncestors: 'Vorfahren anzeigen',
    editInWebtrees: 'In webtrees öffnen und bearbeiten',
    editInWebtreesHint: 'Sie sehen diesen Link, weil Sie den Stammbaum bearbeiten dürfen.',
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
    copied: 'Der Link ist kopiert. Fügen Sie ihn in Ihre Nachricht ein.',
  },
  myPhotos: {
    title: 'Meine Fotos',
    body: 'Sie können ein Foto von sich hinzufügen. Es erscheint auf Ihrem Datensatz und wird allen Mitgliedern gezeigt, die Sie sehen dürfen.',
    rule:
      'Fotos lebender Personen werden im Portal nur gezeigt, wenn die Person sie selbst hochgeladen hat — auch dann, wenn im Stammbaum weitere liegen. Fotos Verstorbener bleiben unverändert.',
    choose: 'Foto auswählen',
    hint: 'JPEG, PNG oder WebP, höchstens 4 MB. Aufnahmeort und andere versteckte Angaben werden beim Hochladen entfernt.',
    remove: 'Entfernen',
    untitled: 'Foto',
    waiting:
      'Das Foto ist gespeichert. Weil an Ihrem Datensatz noch eine Änderung auf Freigabe wartet, erscheint es erst, wenn diese freigegeben ist.',
  },
  person: {
    title: 'Person',
    backToProfile: 'Zurück zu meinem Profil',
    invite: {
      title: 'Noch nicht im Portal',
      body: '{{name}} hat noch keinen Zugang. Sie können eine Einladung erstellen und den Link selbst weitergeben.',
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
        father: 'Ihr Vater',
        mother: 'Ihre Mutter',
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
      'Wo eine Person für Sie nicht freigegeben ist — das sind fast immer die Lebenden — steht nur, dass dort jemand steht: kein Name, keine Daten. Wer sich selbst ins Mitgliederverzeichnis eingetragen hat, wird mit dem Namen aus dem Verzeichnis genannt; aus dem Stammbaum wird auch dann nichts angezeigt.',
  },
  error: {
    title: 'Da ist etwas schiefgelaufen',
    reference: 'Wenn Sie nachfragen, nennen Sie bitte diese Kennung:',
    retry: 'Noch einmal versuchen',
    network: 'Das Portal konnte den Server nicht erreichen. Bitte prüfen Sie Ihre Internetverbindung.',
    not_found: 'Dieser Eintrag wurde nicht gefunden. Vielleicht ist er nicht mehr für Sie freigegeben.',
    not_configured:
      'Das Portal ist noch nicht vollständig eingerichtet. Bitte wenden Sie sich an die Familienverwaltung.',
    server_error: 'Auf dem Server ist ein Fehler aufgetreten. Bitte versuchen Sie es später noch einmal.',
    record_locked:
      'Dieser Eintrag ist gesperrt und kann nicht geändert werden. Bitte wenden Sie sich an die Familienverwaltung.',
    change_pending:
      'Eine frühere Änderung von Ihnen wartet noch auf die Freigabe. Bitte warten Sie, bis sie geprüft wurde.',
    no_linked_record: 'Ihr Konto ist noch mit keiner Person im Stammbaum verknüpft.',
    cannot_reply:
      'Auf diese Nachricht kann hier nicht geantwortet werden — die Absenderadresse gehört zu keinem Konto im Stammbaum.',
    unknown: 'Bitte versuchen Sie es noch einmal.',
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
