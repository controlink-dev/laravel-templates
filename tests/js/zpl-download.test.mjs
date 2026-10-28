import assert from 'node:assert/strict';
import { filenameFromDisposition, missingLabelsNotice, zplErrorMessage } from '../../resources/js/editor/zpl-download.js';

/*
 * CGE-98: the ZPL comes back as an attachment; the file is saved under the name the
 * host gave it in `Content-Disposition`.
 */
{
    assert.equal(filenameFromDisposition('attachment; filename="etiquetas-artigo-v3.zpl"', 'x.zpl'), 'etiquetas-artigo-v3.zpl');
    assert.equal(filenameFromDisposition('attachment; filename=etiquetas.zpl', 'x.zpl'), 'etiquetas.zpl');
    assert.equal(filenameFromDisposition("attachment; filename=\"a.zpl\"; filename*=UTF-8''etiqu%C3%A9tas.zpl", 'x.zpl'), 'etiquétas.zpl');
    assert.equal(filenameFromDisposition('attachment', 'labels.zpl'), 'labels.zpl');
    assert.equal(filenameFromDisposition(null, 'labels.zpl'), 'labels.zpl');
    // A path is never honoured: only the last segment.
    assert.equal(filenameFromDisposition('attachment; filename="../../x.zpl"', 'labels.zpl'), 'x.zpl');
}

/*
 * A refused batch (422) names the articles whose barcode cannot be printed — code and
 * name — after the host's message; any other error is the message alone, or the fallback.
 */
{
    assert.equal(
        zplErrorMessage({ message: 'O lote não pode ser gerado.', articles: [
            { code: 'ART-1', name: 'Porca', reason: 'does_not_fit' },
            { code: 'ART-2', name: 'Parafuso', reason: 'unencodable' },
        ] }, 'Falhou.'),
        'O lote não pode ser gerado. ART-1 (Porca); ART-2 (Parafuso)',
    );
    assert.equal(zplErrorMessage({ message: 'Gotenberg em baixo.' }, 'Falhou.'), 'Gotenberg em baixo.');
    assert.equal(zplErrorMessage({}, 'Falhou.'), 'Falhou.');
    assert.equal(zplErrorMessage(null, 'Falhou.'), 'Falhou.');
    assert.equal(zplErrorMessage({ articles: [{ code: 'ART-1', name: '' }] }, 'Falhou.'), 'Falhou. ART-1');
}

console.log('zpl-download: ok');

/* CGE-98: the host printed the batch short — the status line says how many were left out. */
{
    const headers = (value) => ({ get: (name) => (name === 'X-Labels-Missing' ? value : null) });
    const template = ':n artigo(s) escolhido(s) já não existe(m) e ficaram de fora.';

    assert.equal(missingLabelsNotice(headers('2'), template), '2 artigo(s) escolhido(s) já não existe(m) e ficaram de fora.');
    assert.equal(missingLabelsNotice(headers(null), template), null);
    assert.equal(missingLabelsNotice(headers('0'), template), null);
    assert.equal(missingLabelsNotice(headers('x'), template), null);
    assert.equal(missingLabelsNotice(headers('1'), undefined), '1 chosen article(s) no longer exist and were left out.');
}
