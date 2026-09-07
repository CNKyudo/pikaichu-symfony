import { Controller } from '@hotwired/stimulus';

/*
 * Reprend l'identité (prénom/nom) du compte ou du licencié sélectionné dans
 * un menu déroulant, comme côté Rails (`user_id`/`kyudojin_id` change
 * listeners de `app/javascript/`). Écrase les champs saisis à la main, y
 * compris pour les vider si le placeholder est resélectionné.
 *
 * Connects to data-controller="identity-autofill"
 */
export default class extends Controller {
    static targets = ['source', 'firstname', 'lastname'];

    fill() {
        // Champ enrichi par ux-autocomplete (TomSelect) : les résultats venus
        // du serveur (avec leurs champs prénom/nom) vivent dans l'instance
        // TomSelect, pas dans des <option> classiques créées à la volée.
        const tomSelect = this.sourceTarget.tomselect;
        const identity = tomSelect
            ? tomSelect.options[this.sourceTarget.value]
            : this.sourceTarget.selectedOptions[0]?.dataset;

        this.firstnameTarget.value = identity?.firstname ?? '';
        this.lastnameTarget.value = identity?.lastname ?? '';
    }
}
