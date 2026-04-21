FROM node:20-alpine

WORKDIR /app

# Install dependencies
COPY package*.json ./
RUN npm ci --only=production

# Copy application source
COPY . .

# Expose port (default 3000)
EXPOSE 3000

# Start the application
CMD ["npm", "start"]
