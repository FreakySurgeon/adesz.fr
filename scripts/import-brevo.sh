#!/bin/bash
# Import CSV contacts into Brevo via /contacts/import API
# Usage: BREVO_API_KEY=xxx bash scripts/import-brevo.sh
set -euo pipefail

API_KEY="${BREVO_API_KEY:?Set BREVO_API_KEY env var}"
BASE_URL="https://api.brevo.com/v3"

import_csv() {
    local file="$1"
    local list_id="$2"
    local label="$3"

    # Read file, strip BOM
    local content
    content=$(sed '1s/^\xEF\xBB\xBF//' "$file")

    echo "Importing $label ($file) into list $list_id..."

    local response
    response=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/contacts/import" \
        -H "accept: application/json" \
        -H "content-type: application/json" \
        -H "api-key: $API_KEY" \
        -d "$(jq -n \
            --arg fileBody "$content" \
            --argjson listIds "[$list_id]" \
            '{
                fileBody: $fileBody,
                listIds: $listIds,
                emailBlacklist: false,
                smsBlacklist: false,
                updateExistingContacts: true,
                emptyContactsAttributes: false
            }'
        )")

    local http_code
    http_code=$(echo "$response" | tail -1)
    local body
    body=$(echo "$response" | sed '$d')

    if [[ "$http_code" -ge 200 && "$http_code" -lt 300 ]]; then
        echo "  OK ($http_code): $body"
    else
        echo "  FAILED ($http_code): $body"
        return 1
    fi
}

import_csv "brevo-import/brevo-import-tous.csv" 5 "Tous les contacts"
import_csv "brevo-import/brevo-import-adherents.csv" 3 "Adherents"
import_csv "brevo-import/brevo-import-donateurs.csv" 4 "Donateurs"

echo ""
echo "All imports completed."
