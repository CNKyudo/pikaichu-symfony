import { Controller } from '@hotwired/stimulus';

/*
 * Bascule le menu mobile de la barre de navigation, comme le burger Bulma
 * côté Rails (app/javascript/application.js).
 *
 * Connects to data-controller="navbar"
 */
export default class extends Controller {
    static targets = ['burger', 'menu'];

    toggle() {
        this.burgerTarget.classList.toggle('is-active');
        this.menuTarget.classList.toggle('is-active');
    }
}
