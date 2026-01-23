#!/bin/bash

# Chat Bot Test Script
# Tests key use cases for multiple tenants

API_BASE="https://aimbot.laravel.cloud/api"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test queries - universal for any shop type
declare -a TEST_QUERIES=(
    # Basic search
    "покажи товари"
    "що є в наявності"
    
    # Slang/informal
    "шо маєш"
    "є шось цікаве"
    
    # Budget queries  
    "щось до 1000 грн"
    "дешеве щось"
    
    # Follow-up context
    "а ще?"
    "покажи інші"
    
    # English
    "show me products"
    "what do you have"
    
    # Category guessing
    "подарунок"
    "для друга"
    
    # Negative/edge
    "привіт"
    "дякую"
)

# Function to test a single query
test_query() {
    local tenant_id=$1
    local query=$2
    local session_id="test_session_$(date +%s)_$RANDOM"
    
    # Make request
    response=$(curl -s --max-time 30 "${API_BASE}/chat/stream?tenant_id=${tenant_id}&session_id=${session_id}" \
        -H "Content-Type: application/json" \
        -d "{\"message\": \"${query}\"}" 2>/dev/null)
    
    # Check if we got a response
    if [ -z "$response" ]; then
        echo -e "${RED}✗${NC} TIMEOUT: $query"
        return 1
    fi
    
    # Check for errors
    if echo "$response" | grep -q '"error"'; then
        echo -e "${RED}✗${NC} ERROR: $query"
        echo "   Response: $(echo "$response" | head -c 200)"
        return 1
    fi
    
    # Extract key info from SSE response
    # SSE format: data: {"type":"...","text":"...","products":[...]}
    
    # Count products if any
    product_count=$(echo "$response" | grep -o '"products":\[' | wc -l)
    has_text=$(echo "$response" | grep -o '"text":"[^"]*"' | head -1)
    
    if [ -n "$has_text" ] || [ "$product_count" -gt 0 ]; then
        echo -e "${GREEN}✓${NC} $query"
        if [ "$product_count" -gt 0 ]; then
            echo -e "   ${BLUE}↳ Products shown${NC}"
        fi
        return 0
    else
        echo -e "${YELLOW}?${NC} $query (unclear response)"
        echo "   Response preview: $(echo "$response" | head -c 150)"
        return 2
    fi
}

# Function to run tests for a tenant
run_tenant_tests() {
    local tenant_id=$1
    local tenant_name=$2
    
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  Testing Tenant #${tenant_id} - ${tenant_name}${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo ""
    
    local passed=0
    local failed=0
    local unclear=0
    
    for query in "${TEST_QUERIES[@]}"; do
        result=$(test_query "$tenant_id" "$query")
        exit_code=$?
        echo "$result"
        
        case $exit_code in
            0) ((passed++)) ;;
            1) ((failed++)) ;;
            2) ((unclear++)) ;;
        esac
        
        # Small delay between requests
        sleep 1
    done
    
    echo ""
    echo -e "${BLUE}───────────────────────────────────────────────────────────${NC}"
    echo -e "Results for Tenant #${tenant_id}:"
    echo -e "  ${GREEN}Passed:${NC}  $passed"
    echo -e "  ${RED}Failed:${NC}  $failed"
    echo -e "  ${YELLOW}Unclear:${NC} $unclear"
    echo -e "${BLUE}───────────────────────────────────────────────────────────${NC}"
    
    # Return summary
    echo "$passed $failed $unclear"
}

# Main execution
echo ""
echo -e "${BLUE}╔═══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║          CHATBOT USE CASE TESTING                         ║${NC}"
echo -e "${BLUE}║          $(date '+%Y-%m-%d %H:%M:%S')                              ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════════╝${NC}"

# Test both tenants
run_tenant_tests 2 "Military/Tactical"
run_tenant_tests 5 "Test4/Fashion"

echo ""
echo -e "${GREEN}Testing complete!${NC}"
