import pandas
from sklearn.ensemble import RandomForestRegressor
from io import StringIO

class SolRad:
    model = None

    """
    Train the predictor model using existing data
    """
    def train(self, data):
        df = pandas.DataFrame(data)

        X = df[['sunElevation', 'sunAzimuth', 'cloudiness', 'rain', 'snow']]
        y = df['power']

        # Modell erstellen
        self.model = RandomForestRegressor(n_estimators=100, random_state=42)  # Hier kannst du Hyperparameter anpassen

        # Modell trainieren
        self.model.fit(X, y)

        return

    """
    Predict power based on input and available predictor model
    """
    def predict(self, input):
        #predict the PV power based on the passed variables:
        if self.model is not None:
            predictedPower = self.model.predict(input)
        else:
            predictedPower = None
        return predictedPower
