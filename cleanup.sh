#!/bin/bash

# DigiDocs Cleanup Script
# Removes unnecessary files from the package

echo "🧹 Cleaning up DigiDocs package..."

# Remove test files
echo "📦 Removing test files..."
rm -rf tests/
rm -f phpunit.xml

# Remove development files
echo "📄 Removing development files..."
rm -f DEVELOPMENT_PLAN.md
rm -f repomix-output.xml

# Remove duplicate UserJourneyMapper service wrapper (keep the Agent)
echo "🔧 Removing duplicate implementations..."
rm -f src/Services/UserJourneyMapper.php

# Remove old/deprecated commands
echo "🗑️  Removing deprecated commands..."
rm -f src/Commands/UserDocsCommand.php

# Remove deprecated agents
echo "🤖 Removing deprecated agents..."
rm -f src/Agent/UserDocumentationAgent.php

echo "✅ Cleanup complete!"
echo ""
echo "📊 Summary of removed items:"
echo "  - Test directory and files"
echo "  - Development planning documents"
echo "  - Duplicate UserJourneyMapper service wrapper"
echo "  - Deprecated commands and agents"
echo ""
echo "💡 The package is now production-ready!"