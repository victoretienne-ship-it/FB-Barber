// =====================================================================
// CONFIGURATION DU SALON FB BARBER
// Ce fichier est lu par le site (navigateur) ET par le serveur de
// réservation (PHP, dossier api/). Une seule source pour les horaires,
// les prestations et les coordonnées.
//
// Règles d'écriture (format JSON) :
// - guillemets doubles partout, pas de virgule après le dernier élément ;
// - les commentaires ne sont permis que sur leur propre ligne, comme celle-ci.
// =====================================================================
window.SALON_CONFIG = {
  "name": "FB Barber",

  // Coordonnées affichées sur le site. Laissées vides, elles s'affichent « À renseigner ».
  "address": "35 Av. Paul Doumer, Chaponost, France",
  "phone": "+33 4 89 11 16 32",

  // Serveur de réservation (dossier api/ sur l'hébergement Hostinger).
  // Vide = pas de serveur : le site utilise les canaux ci-dessous.
  "api": "api/",

  // Canaux de secours si aucun serveur ne répond (site sans PHP) :
  // service de formulaire, puis WhatsApp (ex. "33612345678").
  "bookingEndpoint": "",
  "whatsapp": "",

  // Horaires : 0 = dimanche, 1 = lundi … 6 = samedi. Liste vide = fermé.
  "hours": {
    "0": [["09:00", "19:30"]],
    "1": [["14:00", "19:30"]],
    "2": [["09:00", "19:30"]],
    "3": [["09:00", "19:30"]],
    "4": [["09:00", "19:30"]],
    "5": [["09:00", "19:30"]],
    "6": [["09:00", "19:30"]]
  },

  // Fermetures exceptionnelles, ex. ["2026-12-25", "2027-01-01"]
  "closedDates": [],

  // Écart entre deux créneaux (minutes), délai minimum avant un rendez-vous (minutes),
  // nombre de jours ouverts à la réservation.
  "slotStep": 15,
  "leadTime": 60,
  "daysAhead": 28,

  // Prestations. L'identifiant (id) correspond à l'attribut data-service des cartes de l'accueil.
  "services": [
    { "id": "coupe-homme", "name": "Coupe homme", "duration": 45, "price": 25 },
    { "id": "barbe", "name": "Barbe", "duration": 30, "price": 15 },
    { "id": "rasage", "name": "Rasage traditionnel", "duration": 40, "price": 25 },
    { "id": "coupe-enfant", "name": "Coupe enfant", "duration": 30, "price": 18 },
    { "id": "rituel", "name": "Rituel complet", "duration": 90, "price": 55 }
  ]
};
