export const de = {
  app: {
    name: 'Familienportal',
    skipToContent: 'Zum Inhalt springen',
  },
  nav: {
    profile: 'Mein Profil',
    members: 'Mitglieder',
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
    forgotten: 'Passwort vergessen?',
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
  members: {
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
    noRecord: 'Kein verknüpfter Eintrag im Stammbaum',
  },
  member: {
    back: 'Zurück zur Übersicht',
    private: {
      title: 'Keine Daten sichtbar',
      body: 'Zu diesem Mitglied sind für Sie keine Daten aus dem Stammbaum freigegeben.',
    },
  },
  settings: {
    title: 'Einstellungen',
    language: 'Sprache',
    languageHint: 'Gilt für dieses Gerät.',
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
    reference: 'Kennnummer im Familienarchiv',
    relationship: 'Für Sie: {{relationship}}',
    showAncestors: 'Vorfahren anzeigen',
    photos: 'Fotos',
    photoUntitled: 'Foto ohne Titel',
    photoClose: 'Schließen',
    noEvents: 'Zu dieser Person sind keine weiteren Daten hinterlegt.',
    sex: {
      M: 'männlich',
      F: 'weiblich',
      X: 'divers',
      U: 'unbekannt',
    },
  },
  person: {
    title: 'Person',
    backToProfile: 'Zurück zu meinem Profil',
  },
  ancestors: {
    title: 'Vorfahren',
    line: {
      root: 'Ausgangspunkt',
      paternal: 'Väterliche Linie',
      maternal: 'Mütterliche Linie',
    },
    none: {
      title: 'Keine Vorfahren hinterlegt',
      body: 'Zu dieser Person sind im Stammbaum keine Eltern erfasst — oder sie sind für Sie nicht freigegeben.',
    },
    privacyNote:
      'Es werden nur Personen angezeigt, die für Sie freigegeben sind. Wo eine Linie endet, kann es sein, dass sie im Stammbaum weitergeht.',
  },
  error: {
    title: 'Da ist etwas schiefgelaufen',
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
    unknown: 'Bitte versuchen Sie es noch einmal.',
    pageNotFound: {
      title: 'Diese Seite gibt es nicht',
      body: 'Vielleicht hat sich die Adresse geändert.',
      action: 'Zu meinem Profil',
    },
  },
  common: {
    loading: 'Wird geladen …',
  },
}

export type Translations = typeof de
