import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    select(event) {
        const tab = event.currentTarget.dataset.tab;

        this.element.querySelectorAll('.tabs li').forEach((li) => {
            li.classList.remove('is-active');
        });

        event.currentTarget.parentElement.classList.add('is-active');

        this.element.querySelectorAll('.tab-content').forEach((content) => {
            content.classList.add('is-hidden');
        });

        this.element
            .querySelector(`#${tab}`)
            .classList.remove('is-hidden');
    }
}