import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle() {
        this.element
            .querySelectorAll('td:not(.shared-cell)')
            .forEach((cell) => {
                cell.classList.toggle('has-background-info');
            });
    }
}