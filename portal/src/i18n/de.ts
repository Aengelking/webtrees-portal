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
    forgotten: 'Passwort vergessen? Bitte wenden Sie sich an die Familienverwaltung.',
    failed: 'Benutzername oder Passwort ist falsch. Bitte versuchen Sie es noch einmal.',
    missing: 'Bitte füllen Sie beide Felder aus.',
  },
  profile: {
    title: 'Mein Profil',
    noRecord: {
      title: 'Ihr Eintrag im Stammbaum fehlt noch',
      body: 'Ihr Konto ist noch mit keiner Person im Stammbaum verknüpft. Die Familienverwaltung kann das einrichten.',
    },
    readOnly: 'In dieser Version können Sie Ihre Daten ansehen, aber noch nicht ändern.',
    openInWebtrees: 'Stammbaum und Diagramme öffnen',
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
    directoryChange:
      'Diese Einstellung kann derzeit nur die Familienverwaltung ändern. Schreiben Sie ihr, wenn Sie das ändern möchten.',
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
    noEvents: 'Zu dieser Person sind keine weiteren Daten hinterlegt.',
    sex: {
      M: 'männlich',
      F: 'weiblich',
      X: 'divers',
      U: 'unbekannt',
    },
  },
  error: {
    title: 'Da ist etwas schiefgelaufen',
    retry: 'Noch einmal versuchen',
    network: 'Das Portal konnte den Server nicht erreichen. Bitte prüfen Sie Ihre Internetverbindung.',
    not_found: 'Dieser Eintrag wurde nicht gefunden. Vielleicht ist er nicht mehr für Sie freigegeben.',
    not_configured:
      'Das Portal ist noch nicht vollständig eingerichtet. Bitte wenden Sie sich an die Familienverwaltung.',
    server_error: 'Auf dem Server ist ein Fehler aufgetreten. Bitte versuchen Sie es später noch einmal.',
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
