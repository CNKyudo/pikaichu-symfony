import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/*
 * Glisser-déposer pour réordonner les participants au sein d'une équipe,
 * porté de `app/javascript/controllers/drag_controller.js` (Rails).
 *
 * Connects to data-controller="reorder"
 */
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.sortable = Sortable.create(this.element, {
            handle: '[data-reorder-handle]',
            onEnd: this.end.bind(this),
        });
    }

    disconnect() {
        this.sortable?.destroy();
    }

    end(event) {
        const item = event.item;
        // L'URL est générée côté serveur avec l'identifiant factice 0 en guise
        // de gabarit (voir teaming/edit.html.twig) : on le remplace ici par
        // l'identifiant réel du participant déplacé.
        const url = this.urlValue.replace('/0/reorder', '/' + item.dataset.reorderId + '/reorder');

        const data = new FormData();
        data.append('index', event.newIndex + 1);
        data.append('_token', item.dataset.reorderToken);

        fetch(url, { method: 'POST', body: data });
    }
}
