import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

/*
 * Ajoute une image à un exercice, une à la fois (#38). Posé sur la planche
 * (`/exercise/images`) ou la section Images de l'éditeur, autour des bandes
 * d'images et de la modale commune (`exercise/_image_dialog`).
 *
 *   « + » d'une bande → sélecteur de fichier → modale avec l'aperçu final →
 *   « Ajouter » (ou Entrée) → envoi, la bande de l'exercice est remplacée par le
 *   Turbo Stream de la réponse. La page ne bouge pas.
 *
 * La mise en forme est la même pour toutes les images du site :
 *   - carré de SIZE px, recadré sur l'axe long (haut / centre / bas pour une
 *     photo en hauteur, gauche / centre / droite en largeur), agrandi au besoin ;
 *   - fond papier sous une image transparente, JPEG à QUALITY ;
 *   - le logo Kadens (PNG transparent, tel quel) en bas à droite ;
 *   - aucune métadonnée (ré-encodage canvas), orientation EXIF appliquée avant.
 *
 * Le serveur (ExerciseImageStorage) refuse tout ce qui n'est pas un JPEG de
 * SIZE × SIZE : c'est lui qui garantit le format, ce contrôleur le fabrique.
 */
const SIZE = 1080; // = ExerciseImageStorage::SIZE
const QUALITY = 0.82;
const LOGO = 0.14; // côté du logo, en part du carré
const MARGIN = 0.03; // écart au bord, en part du carré

export default class extends Controller {
    static targets = ['input', 'dialog', 'preview', 'name', 'cropGroup', 'align', 'status', 'confirm'];
    static values = { logo: String, from: String };

    connect() {
        this.bitmap = null;
        this.blob = null;
        this.previewUrl = null;
        this.align = 0.5;
        this.run = 0;
        // Comme `dialog` : pas d'instantané Turbo pris modale ouverte.
        this.beforeCache = () => this.close();
        document.addEventListener('turbo:before-cache', this.beforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.beforeCache);
        this.reset();
    }

    /* « + » d'une bande : retient l'exercice visé et ouvre le sélecteur. */
    pick({ params }) {
        this.target = { url: params.url, token: params.token, name: params.name };
        this.repick();
    }

    repick() {
        this.inputTarget.value = '';
        this.inputTarget.click();
    }

    async picked() {
        const file = this.inputTarget.files[0];
        if (!file || !this.target) {
            return;
        }

        this.nameTarget.textContent = this.target.name;
        this.blob = null;
        this.confirmTarget.disabled = true;
        if (!this.dialogTarget.open) {
            this.dialogTarget.showModal();
        }

        if (typeof createImageBitmap !== 'function') {
            this.say('Ce navigateur ne sait pas préparer les images : essaie depuis un navigateur récent.');
            return;
        }

        try {
            this.bitmap?.close();
            this.bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch (error) {
            this.bitmap = null;
            this.previewTarget.hidden = true;
            this.cropGroupTarget.hidden = true;
            this.say('Ce fichier ne se lit pas dans ce navigateur (HEIC ?). Exporte-le en JPEG.');
            return;
        }

        this.fileName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
        this.align = 0.5;
        this.labelCrop();
        await this.render();
    }

    async crop({ params }) {
        this.align = Number(params.align);
        await this.render();
    }

    async confirm() {
        if (!this.blob || !this.target) {
            return;
        }

        this.confirmTarget.disabled = true;
        this.say('Envoi…');

        const body = new FormData();
        body.append('_token', this.target.token);
        body.append('from', this.fromValue);
        body.append('images[]', new File([this.blob], this.fileName, { type: 'image/jpeg' }));

        try {
            const response = await fetch(this.target.url, {
                method: 'POST',
                body,
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            renderStreamMessage(await response.text());
            this.close();
        } catch (error) {
            this.confirmTarget.disabled = false;
            this.say('Envoi impossible. Vérifie la connexion et réessaie.');
        }
    }

    close() {
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }

    /* Clic sur le fond (hors de la carte) : on ferme. */
    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    /* Événement `close` du <dialog>, quelle qu'en soit la cause (Échap compris). */
    closed() {
        this.reset();
    }

    async render() {
        const run = ++this.run;
        this.confirmTarget.disabled = true;
        this.say('Préparation…');
        this.alignTargets.forEach((button) => button.setAttribute('aria-pressed', Number(button.dataset.exerciseImagesAlignParam) === this.align ? 'true' : 'false'));

        try {
            const blob = await this.draw(await this.loadLogo());
            if (run !== this.run) {
                return; // un autre cadrage a été demandé entre-temps
            }

            this.blob = blob;
            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }
            this.previewUrl = URL.createObjectURL(blob);
            this.previewTarget.src = this.previewUrl;
            this.previewTarget.hidden = false;
            this.confirmTarget.disabled = false;
            this.confirmTarget.focus();
            this.say(`${SIZE} × ${SIZE}, ${Math.round(blob.size / 1024)} Ko, sans métadonnées.`);
        } catch (error) {
            if (run === this.run) {
                this.say('Préparation impossible dans ce navigateur.');
            }
        }
    }

    async draw(logo) {
        const bitmap = this.bitmap;
        const side = Math.min(bitmap.width, bitmap.height);
        const sx = (bitmap.width - side) * this.align;
        const sy = (bitmap.height - side) * this.align;

        const canvas = document.createElement('canvas');
        canvas.width = SIZE;
        canvas.height = SIZE;
        const context = canvas.getContext('2d');
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';

        context.fillStyle = this.token('--color-surface-raised', '#ffffff');
        context.fillRect(0, 0, SIZE, SIZE);
        context.drawImage(bitmap, sx, sy, side, side, 0, 0, SIZE, SIZE);

        // Le PNG tel quel, transparence comprise, calé sur le coin bas droit.
        const height = Math.round(SIZE * LOGO);
        const width = Math.round(height * (logo.naturalWidth / logo.naturalHeight));
        const margin = Math.round(SIZE * MARGIN);
        context.drawImage(logo, SIZE - margin - width, SIZE - margin - height, width, height);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
        if (!blob) {
            throw new Error('encodage impossible');
        }

        return blob;
    }

    /* Haut / bas pour une photo en hauteur, gauche / droite en largeur, rien pour un carré. */
    labelCrop() {
        const { width, height } = this.bitmap;
        const labels = height > width ? ['Haut', 'Centre', 'Bas'] : ['Gauche', 'Centre', 'Droite'];
        this.alignTargets.forEach((button, index) => { button.textContent = labels[index]; });
        this.cropGroupTarget.hidden = width === height;
    }

    /* Le logo, chargé une fois. Même origine : le canvas reste exportable. */
    loadLogo() {
        this.logo ??= new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => {
                this.logo = null;
                reject(new Error('logo introuvable'));
            };
            image.src = this.logoValue;
        });

        return this.logo;
    }

    /* Couleur lue sur les tokens du site, jamais écrite en dur. */
    token(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    say(message) {
        this.statusTarget.textContent = message;
    }

    reset() {
        this.run++;
        this.bitmap?.close();
        this.bitmap = null;
        this.blob = null;
        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }
        if (this.hasPreviewTarget) {
            this.previewTarget.hidden = true;
            this.previewTarget.removeAttribute('src');
        }
        if (this.hasInputTarget) {
            this.inputTarget.value = '';
        }
    }
}
