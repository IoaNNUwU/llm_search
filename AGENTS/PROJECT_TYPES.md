# Bitrix API docs

Link to GitHub repo: https://github.com/bitrix-tools/b24-rest-docs.git - do not include link field in the upload form.

Uploaded folder of this type gets converted to link showed by AGENT:

0. Ignore files starting with underscore `_`.
1. Strip uploaded folder name.
2. Strip `.md` suffix.
3. Concatenate `https://apidocs.bitrix24.ru/` with a path.
4. Add `.html`

## Bitrix API docs examples:

- `uploaded_folder/first-steps/index.md` -> `https://apidocs.bitrix24.ru/first-steps/index.html`
- `uploaded_folder/first-steps/access-to-rest-api.md` -> `https://apidocs.bitrix24.ru/first-steps/access-to-rest-api.html`
- `uploaded_folder/ai-tools/vibecode.md` -> `https://apidocs.bitrix24.ru/ai-tools/vibecode.html`
- `uploaded_folder/error-codes.md` -> `https://apidocs.bitrix24.ru/error-codes.html`

# Gramax projects

Include link field in the upload form - there could be multiple projects.

Uploaded folder of this type gets converted to link showed by AGENT:

1. Strip uploaded folder name.
2. Strip `.md` suffix.
3. Concatenate link specified by the user with a path.
4. DO NOT add `.html`
5. Exception is `uploaded_folder/folder1/_index.md` file which is converted to `provided_url/folder1`

## Gramax examples:

- `uploaded_folder/first-steps/_index.md` -> `provided_link/first-steps`
- `uploaded_folder/first-steps/access-to-rest-api.md` -> `provided_link/first-steps/access-to-rest-api`
- `uploaded_folder/ai-tools/vibecode.md` -> `provided_link/ai-tools/vibecode`
- `uploaded_folder/error-codes.md` -> `https://apidocs.bitrix24.ru/error-codes`